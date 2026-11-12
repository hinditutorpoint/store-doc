<?php

namespace StoreDoc\Laravel\Storage;

use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * High-performance smart caching manager for StoreDoc.
 * Caches object metadata and signed URLs to eliminate redundant HTTP requests,
 * with auto-refreshing TTL that invalidates URLs before signature expiration.
 */
class StoreDocCache
{
    /** Maximum number of entries kept in the in-memory fallback cache. */
    protected const MAX_MEMORY_ENTRIES = 1000;

    /** Lifetime of the per-path version counter (30 days). */
    protected const VERSION_TTL = 2592000;

    protected array $config;

    protected bool $enabled;

    protected ?string $store;

    protected string $prefix;

    protected int $objectTtl;

    protected int $urlSafetyMargin;

    protected int $urlDefaultTtl;

    /**
     * In-memory array cache for non-Laravel or fallback execution.
     */
    protected static array $memoryCache = [];

    public function __construct(array $config = [])
    {
        $cacheConfig = $config['cache'] ?? [];

        $this->enabled = (bool) ($cacheConfig['enabled'] ?? true);
        $this->store = $cacheConfig['store'] ?? null;
        $this->prefix = trim((string) ($cacheConfig['prefix'] ?? 'storedoc'), ':');
        $this->objectTtl = (int) ($cacheConfig['object_ttl'] ?? 3600); // 1 hour
        $this->urlSafetyMargin = (int) ($cacheConfig['url_safety_margin'] ?? 300); // 5 minutes
        $this->urlDefaultTtl = max(60, (int) ($cacheConfig['url_default_ttl'] ?? 3600)); // 1 hour
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get cached object metadata by path.
     */
    public function getObject(string $bucket, string $path): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $key = $this->objectKey($bucket, $path);

        return $this->get($key);
    }

    /**
     * Store object metadata in cache.
     */
    public function putObject(string $bucket, string $path, array $data, ?int $ttl = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = $this->objectKey($bucket, $path);
        $this->put($key, $data, $ttl ?? $this->objectTtl);
    }

    /**
     * Forget cached object metadata for a given path.
     */
    public function forgetObject(string $bucket, string $path): void
    {
        $key = $this->objectKey($bucket, $path);
        $this->forget($key);
    }

    /**
     * Get cached URL if it exists and has not expired.
     */
    public function getUrl(string $bucket, string $path, string $type = 'public', array $context = []): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $key = $this->urlKey($bucket, $path, $type, $context);
        $url = $this->get($key);

        if ($url && is_string($url)) {
            if (! $this->isUrlExpired($url)) {
                return $url;
            }
            $this->forget($key);
        }

        return null;
    }

    /**
     * Put generated URL into cache with safe expiration.
     */
    public function putUrl(string $bucket, string $path, string $type, string $url, array $context = []): void
    {
        if (! $this->enabled || empty($url)) {
            return;
        }

        $key = $this->urlKey($bucket, $path, $type, $context);
        $ttl = $this->calculateUrlTtl($url);

        $this->put($key, $url, $ttl);
    }

    /**
     * Forget all cache (metadata and URLs) related to a specific file path.
     *
     * [C-01] Every cached URL is stored with a context (expiration, filename,
     *        disposition), so a simple key deletion cannot reach them. The
     *        per-path version counter is bumped instead, which invalidates
     *        every entry (metadata and URLs) for that path at once. Without
     *        this, an overwritten file could keep serving a stale signed URL.
     */
    public function forgetPath(string $bucket, string $path): void
    {
        $this->forgetObject($bucket, $path);

        // Legacy keys (without a version segment) are removed as well.
        $this->forget("{$this->prefix}:obj:{$bucket}:".md5(trim($path, '/')));
        foreach (['public', 'preview', 'download', 'temporary'] as $type) {
            $this->forget($this->legacyUrlKey($bucket, $path, $type));
        }

        $this->bumpVersion($bucket, $path);
    }

    /**
     * Bump the version counter for a path, invalidating every cached entry.
     */
    protected function bumpVersion(string $bucket, string $path): void
    {
        $key = $this->versionKey($bucket, $path);
        $current = (int) ($this->get($key) ?? 0);
        $this->put($key, $current + 1, self::VERSION_TTL);
    }

    /**
     * Get the current version counter for a path.
     */
    protected function version(string $bucket, string $path): int
    {
        return (int) ($this->get($this->versionKey($bucket, $path)) ?? 0);
    }

    /**
     * Check if a signed URL is expired or expiring within the safety margin.
     */
    public function isUrlExpired(string $url): bool
    {
        $expires = $this->urlExpiry($url);

        return $expires !== null && $expires <= (time() + $this->urlSafetyMargin);
    }

    /**
     * Calculate seconds remaining before URL expiry minus safety margin.
     */
    public function calculateUrlTtl(string $url, ?int $defaultSeconds = null): int
    {
        $expires = $this->urlExpiry($url);

        if ($expires !== null) {
            $remaining = $expires - time() - $this->urlSafetyMargin;

            return max(60, $remaining);
        }

        // The expiry could not be determined (for example an S3 style URL that
        // is not readable), so fall back to the configured default instead of
        // caching the URL for a week.
        return $defaultSeconds ?? $this->urlDefaultTtl;
    }

    /**
     * Resolve the absolute expiry timestamp of a signed URL.
     *
     * Supports Laravel signed routes ("expires=<unix timestamp>") as well as
     * S3 style presigned URLs ("X-Amz-Date" + "X-Amz-Expires").
     */
    protected function urlExpiry(string $url): ?int
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! $query) {
            return null;
        }

        parse_str($query, $params);

        foreach (['expires', 'Expires'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key])) {
                return (int) $params[$key];
            }
        }

        if (isset($params['X-Amz-Expires']) && isset($params['X-Amz-Date'])) {
            $timestamp = strtotime((string) $params['X-Amz-Date']);
            if ($timestamp !== false) {
                return $timestamp + (int) $params['X-Amz-Expires'];
            }
        }

        return null;
    }

    /**
     * Clear all in-memory cache (useful for testing).
     */
    public static function flushMemory(): void
    {
        static::$memoryCache = [];
    }

    protected function objectKey(string $bucket, string $path): string
    {
        $norm = trim($path, '/');

        return "{$this->prefix}:obj:{$bucket}:".md5($norm).':v'.$this->version($bucket, $path);
    }

    protected function urlKey(string $bucket, string $path, string $type, array $context = []): string
    {
        $norm = trim($path, '/');
        $hashContext = ! empty($context) ? ':'.md5((string) json_encode($context)) : '';

        return "{$this->prefix}:url:{$bucket}:{$type}:".md5($norm).':v'.$this->version($bucket, $path).$hashContext;
    }

    /**
     * Key format used before the version counter was introduced.
     */
    protected function legacyUrlKey(string $bucket, string $path, string $type): string
    {
        $norm = trim($path, '/');

        return "{$this->prefix}:url:{$bucket}:{$type}:".md5($norm);
    }

    protected function versionKey(string $bucket, string $path): string
    {
        $norm = trim($path, '/');

        return "{$this->prefix}:ver:{$bucket}:".md5($norm);
    }

    protected function get(string $key): mixed
    {
        if ($this->hasLaravelCache()) {
            try {
                return $this->cacheStore()->get($key);
            } catch (\Throwable) {
                // Fallback to memory cache
            }
        }

        $item = static::$memoryCache[$key] ?? null;
        if ($item && $item['expires_at'] > time()) {
            return $item['value'];
        }

        return null;
    }

    protected function put(string $key, mixed $value, int|DateTimeInterface $ttl): void
    {
        $ttlSeconds = $ttl instanceof DateTimeInterface
            ? max(60, $ttl->getTimestamp() - time())
            : (int) $ttl;

        if ($this->hasLaravelCache()) {
            try {
                $this->cacheStore()->put($key, $value, $ttlSeconds);

                return;
            } catch (\Throwable) {
                // Fallback to memory cache
            }
        }

        $this->pruneMemoryCache();

        static::$memoryCache[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttlSeconds,
        ];
    }

    /**
     * Keep the in-memory fallback cache bounded.
     *
     * [M-05] Long running workers (queues, Octane) would otherwise grow the
     *        static array without limit.
     */
    protected function pruneMemoryCache(): void
    {
        if (count(static::$memoryCache) < self::MAX_MEMORY_ENTRIES) {
            return;
        }

        uasort(static::$memoryCache, fn ($a, $b) => ($a['expires_at'] ?? 0) <=> ($b['expires_at'] ?? 0));

        $overflow = count(static::$memoryCache) - self::MAX_MEMORY_ENTRIES + 1;
        foreach (array_keys(static::$memoryCache) as $key) {
            if ($overflow-- <= 0) {
                break;
            }
            unset(static::$memoryCache[$key]);
        }
    }

    protected function forget(string $key): void
    {
        if ($this->hasLaravelCache()) {
            try {
                $this->cacheStore()->forget($key);
            } catch (\Throwable) {
                // Ignore
            }
        }

        unset(static::$memoryCache[$key]);
    }

    protected function hasLaravelCache(): bool
    {
        return class_exists(Cache::class) && function_exists('app') && app()->bound('cache');
    }

    protected function cacheStore()
    {
        return $this->store ? Cache::store($this->store) : Cache::store();
    }
}
