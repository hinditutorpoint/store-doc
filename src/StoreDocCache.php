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
    protected array $config;

    protected bool $enabled;

    protected ?string $store;

    protected string $prefix;

    protected int $objectTtl;

    protected int $urlSafetyMargin;

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
     */
    public function forgetPath(string $bucket, string $path): void
    {
        $this->forgetObject($bucket, $path);

        foreach (['public', 'preview', 'download', 'temporary'] as $type) {
            $key = $this->urlKey($bucket, $path, $type);
            $this->forget($key);
        }
    }

    /**
     * Check if a signed URL is expired or expiring within the safety margin.
     */
    public function isUrlExpired(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $params);
            $exp = $params['expires'] ?? $params['Expires'] ?? null;
            if ($exp && is_numeric($exp)) {
                return (int) $exp <= (time() + $this->urlSafetyMargin);
            }
        }

        return false;
    }

    /**
     * Calculate seconds remaining before URL expiry minus safety margin.
     */
    public function calculateUrlTtl(string $url, int $defaultSeconds = 604800): int
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $params);
            $exp = $params['expires'] ?? $params['Expires'] ?? null;
            if ($exp && is_numeric($exp)) {
                $remaining = (int) $exp - time() - $this->urlSafetyMargin;

                return max(60, $remaining);
            }
        }

        return $defaultSeconds;
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

        return "{$this->prefix}:obj:{$bucket}:".md5($norm);
    }

    protected function urlKey(string $bucket, string $path, string $type, array $context = []): string
    {
        $norm = trim($path, '/');
        $hashContext = ! empty($context) ? ':'.md5(json_encode($context)) : '';

        return "{$this->prefix}:url:{$bucket}:{$type}:".md5($norm).$hashContext;
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

        static::$memoryCache[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttlSeconds,
        ];
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

