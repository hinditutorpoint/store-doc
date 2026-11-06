<?php

namespace StoreDoc\Laravel\Storage;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class StoreDocClient
{
    protected Client $client;

    protected string $apiUrl;

    protected string $accessKeyId;

    protected string $secretAccessKey;

    protected ?string $bucket;

    protected int $maxTemporaryUrlMinutes;

    protected int $maxListingPages;

    protected string $authMode;

    protected int $maxRetries;

    protected bool $verifySsl;

    public function __construct(array $config)
    {
        $apiUrl = $config['api_url'] ?? null;
        $accessKeyId = $config['access_key_id'] ?? null;
        $secretAccessKey = $config['secret_access_key'] ?? null;

        if (! is_string($apiUrl) || trim($apiUrl) === '') {
            throw new InvalidArgumentException('A Store-Doc API URL is required.');
        }

        if (! is_string($accessKeyId) || trim($accessKeyId) === '' ||
            ! is_string($secretAccessKey) || trim($secretAccessKey) === '') {
            throw new InvalidArgumentException('Store-Doc API credentials are required.');
        }
        $accessKeyId = trim($accessKeyId);
        $secretAccessKey = trim($secretAccessKey);
        if (str_contains($accessKeyId, ':') ||
            preg_match('/[\x00-\x1f\x7f]/', $accessKeyId.$secretAccessKey)) {
            throw new InvalidArgumentException('Store-Doc API credentials contain invalid characters.');
        }

        $parts = parse_url(trim($apiUrl));
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if (! is_array($parts) ||
            ! in_array($scheme, ['http', 'https'], true) ||
            empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The Store-Doc API URL must be an absolute HTTP(S) URL without credentials, query, or fragment.');
        }

        $allowInsecureHttp = $config['allow_insecure_http'] ?? false;
        $allowInsecureTls = $config['allow_insecure_tls'] ?? false;
        $verifySsl = $config['verify_ssl'] ?? true;
        if (! is_bool($allowInsecureHttp) || ! is_bool($allowInsecureTls) || ! is_bool($verifySsl)) {
            throw new InvalidArgumentException('TLS and insecure transport options must be booleans.');
        }
        if (($scheme === 'http' && ! $allowInsecureHttp) ||
            (! $verifySsl && ! $allowInsecureTls)) {
            throw new InvalidArgumentException('Insecure HTTP or TLS verification requires an explicit opt-in.');
        }

        $timeout = $config['timeout'] ?? 30;
        $connectTimeout = $config['connect_timeout'] ?? 10;
        if (! is_numeric($timeout) || (float) $timeout <= 0 ||
            ! is_finite((float) $timeout) || (float) $timeout > 300 ||
            ! is_numeric($connectTimeout) || (float) $connectTimeout <= 0 ||
            ! is_finite((float) $connectTimeout) || (float) $connectTimeout > 120) {
            throw new InvalidArgumentException('HTTP timeouts must be positive and within the supported limits.');
        }

        $maxTemporaryUrlMinutes = $config['max_temporary_url_minutes'] ?? 1440;
        if (! is_numeric($maxTemporaryUrlMinutes) || (int) $maxTemporaryUrlMinutes < 1) {
            throw new InvalidArgumentException('The maximum temporary URL lifetime must be a positive number of minutes.');
        }
        $maxListingPages = $config['max_listing_pages'] ?? 1000;
        if (! is_numeric($maxListingPages) || (int) $maxListingPages < 1) {
            throw new InvalidArgumentException('The maximum listing page count must be a positive number.');
        }

        // [S-01] Auth mode: 'hmac' (recommended) or 'basic' (legacy, secret in header)
        $authMode = $config['auth_mode'] ?? 'basic';
        if (! in_array($authMode, ['hmac', 'basic'], true)) {
            throw new InvalidArgumentException('The auth_mode must be "hmac" or "basic".');
        }

        // [S-03] Retry configuration
        $maxRetries = $config['max_retries'] ?? 3;
        if (! is_numeric($maxRetries) || (int) $maxRetries < 0 || (int) $maxRetries > 10) {
            throw new InvalidArgumentException('max_retries must be between 0 and 10.');
        }

        $apiPath = rtrim($parts['path'] ?? '', '/');
        $baseUrl = rtrim($apiUrl, '/');
        $this->apiUrl = (strtolower($apiPath) === '/api' ? $baseUrl : $baseUrl.'/api').'/';
        $this->accessKeyId = $accessKeyId;
        $this->secretAccessKey = $secretAccessKey;
        $this->maxTemporaryUrlMinutes = (int) $maxTemporaryUrlMinutes;
        $this->maxListingPages = (int) $maxListingPages;
        $this->authMode = $authMode;
        $this->maxRetries = (int) $maxRetries;
        $this->verifySsl = $verifySsl;

        $bucket = $config['bucket'] ?? null;
        if ($bucket !== null && ! is_string($bucket)) {
            throw new InvalidArgumentException('The Store-Doc bucket identifier must be a string.');
        }
        $this->bucket = is_string($bucket) && trim($bucket) !== '' ? trim($bucket) : null;

        $this->client = $this->createHttpClient([
            'base_uri' => $this->apiUrl,
            'timeout' => (float) $timeout,
            'connect_timeout' => (float) $connectTimeout,
            'verify' => $verifySsl,
            'allow_redirects' => false,
        ]);

        // [S-01] Warn when legacy basic auth mode is used
        // [S-02] Warn when insecure transport options are enabled
        try {
            if ($this->authMode === 'basic') {
                $this->log('Using legacy basic auth mode. The API secret is included in request headers. Consider switching to HMAC auth (auth_mode=hmac).', [], 'warning');
            }
            if ($scheme === 'http' && $allowInsecureHttp) {
                $this->log('Insecure HTTP transport is enabled. API credentials may be sent unencrypted.', [], 'warning');
            }
            if (! $verifySsl && $allowInsecureTls) {
                $this->log('TLS certificate verification is disabled. Connections may be vulnerable to MITM attacks.', [], 'warning');
            }
        } catch (\Throwable) {
            // Log facade may not be available during early bootstrapping.
        }
    }

    /**
     * Create an HTTP client with retry middleware.
     *
     * [S-03] Adds exponential-backoff retry for transient failures and rate limits.
     */
    protected function createHttpClient(array $options): Client
    {
        $stack = HandlerStack::create();

        if ($this->maxRetries > 0) {
            $stack->push(Middleware::retry(
                $this->retryDecider(),
                $this->retryDelay()
            ), 'retry');
        }

        $options['handler'] = $stack;

        return new Client($options);
    }

    /**
     * [S-03] Determine whether a failed request should be retried.
     */
    protected function retryDecider(): callable
    {
        return function (int $retries, RequestInterface $request, ?ResponseInterface $response, ?\Throwable $exception): bool {
            if ($retries >= $this->maxRetries) {
                return false;
            }

            // Retry on connection errors (DNS, timeout, reset)
            if ($exception instanceof ConnectException) {
                return true;
            }

            // Retry on rate-limit and server errors
            if ($response) {
                return in_array($response->getStatusCode(), [429, 502, 503, 504], true);
            }

            return false;
        };
    }

    /**
     * [S-03] Calculate the delay before the next retry attempt.
     *
     * Respects the Retry-After header; falls back to exponential backoff.
     */
    protected function retryDelay(): callable
    {
        return function (int $retries, ?ResponseInterface $response): int {
            if ($response && $response->hasHeader('Retry-After')) {
                $retryAfter = $response->getHeaderLine('Retry-After');
                if (is_numeric($retryAfter) && (int) $retryAfter > 0) {
                    return (int) ((float) $retryAfter * 1000);
                }
            }

            // Exponential backoff: 1 s, 2 s, 4 s, …
            return (int) (1000 * pow(2, $retries));
        };
    }

    /**
     * Build authentication headers for the current request.
     *
     * [S-01] HMAC mode: the secret key never leaves the client.
     *        Basic mode (legacy): the secret is sent in the header — avoid in production.
     */
    protected function getAuthHeaders(string $method = '', string $uri = ''): array
    {
        if ($this->authMode === 'hmac') {
            $timestamp = (string) time();
            $signature = $this->generateHmacSignature($method, $uri, $timestamp);

            return [
                'Authorization' => "HMAC-SHA256 Credential={$this->accessKeyId}, Timestamp={$timestamp}, Signature={$signature}",
                'Accept' => 'application/json',
            ];
        }

        // Legacy basic mode
        return [
            'X-Api-Key' => "{$this->accessKeyId}:{$this->secretAccessKey}",
            'Accept' => 'application/json',
        ];
    }

    /**
     * [S-01] Generate an HMAC-SHA256 signature for the request.
     *
     * The canonical string is: METHOD\nURI\nTIMESTAMP
     * The secret key is used as the HMAC key and never transmitted.
     */
    protected function generateHmacSignature(string $method, string $uri, string $timestamp): string
    {
        $canonical = implode("\n", [strtoupper($method), $uri, $timestamp]);

        return hash_hmac('sha256', $canonical, $this->secretAccessKey);
    }

    /**
     * [S-01] Redact credentials from a string (e.g. exception messages).
     */
    protected function redactCredentials(string $message): string
    {
        $message = preg_replace('/X-Api-Key:\s*[^\s,}\]"]+/i', 'X-Api-Key: [REDACTED]', $message);
        $message = preg_replace('/Authorization:\s*[^\r\n]+/i', 'Authorization: [REDACTED]', $message);

        if ($this->secretAccessKey !== '' && strlen($this->secretAccessKey) >= 8) {
            $message = str_replace($this->secretAccessKey, '[REDACTED]', $message);
        }

        return $message;
    }

    /**
     * Log a redacted operational message.
     */
    public function log(string $message, array $context = [], string $level = 'info'): void
    {
        Log::log($level, "[StoreDoc] {$message}", $context);
    }

    /**
     * Execute an HTTP request with automatic auth-header injection and
     * credential redaction on failure.
     *
     * [S-01] Auth headers are injected here so callers never handle secrets.
     */
    protected function request(string $method, string $uri, array $options = [], string $operation = 'API request')
    {
        // Inject auth headers automatically
        $options['headers'] = array_merge(
            $this->getAuthHeaders($method, $uri),
            $options['headers'] ?? []
        );

        try {
            return $this->client->request($method, $uri, $options);
        } catch (GuzzleException $e) {
            $safeContext = ['exception' => get_class($e)];
            if ($e instanceof RequestException && $e->getResponse()) {
                $safeContext['status'] = $e->getResponse()->getStatusCode();
            }
            $this->log("{$operation} failed", $safeContext, 'error');

            // [S-01] Redact credentials from the wrapped message
            throw new RuntimeException(
                'Store-Doc '.$operation.' failed: '.$this->redactCredentials($e->getMessage()),
                0,
                $e
            );
        }
    }

    protected function requestJson(string $method, string $uri, array $options = [], string $operation = 'API request'): array
    {
        $response = $this->request($method, $uri, $options, $operation);

        try {
            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Store-Doc {$operation} returned invalid JSON.", 0, $e);
        }

        if (! is_array($data)) {
            throw new RuntimeException("Store-Doc {$operation} returned an invalid response.");
        }

        return $data;
    }

    protected function requireBucket(): string
    {
        if ($this->bucket === null) {
            throw new InvalidArgumentException('A Store-Doc bucket is required for this operation.');
        }

        return $this->bucket;
    }

    protected function objectUri(string $objectId, string $suffix = ''): string
    {
        if ($objectId === '' || strlen($objectId) > 255 || preg_match('/[\x00-\x20\x7f]/', $objectId)) {
            throw new InvalidArgumentException('The object identifier is invalid.');
        }

        return 'storage/objects/'.rawurlencode($objectId).$suffix;
    }

    protected function normalizePath(string $path, bool $allowEmpty = false): string
    {
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) {
            throw new InvalidArgumentException('Storage paths cannot contain control characters or backslashes.');
        }

        $path = trim($path, '/');
        if ($path === '' && ! $allowEmpty) {
            throw new InvalidArgumentException('A non-empty storage path is required.');
        }
        if (strlen($path) > 1024) {
            throw new InvalidArgumentException('Storage paths cannot exceed 1024 bytes.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Storage paths cannot contain dot segments.');
            }
        }

        return $path;
    }

    /**
     * Validate a presigned URL returned by the API.
     *
     * [S-08] When TLS verification is enabled, HTTP presigned URLs are rejected
     *        to prevent protocol-downgrade attacks via MITM.
     */
    public function validatePresignedUrl(mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Store-Doc did not return a download URL.');
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if (! is_array($parts) ||
            ! in_array($scheme, ['http', 'https'], true) ||
            empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Store-Doc returned an unsafe download URL.');
        }

        // [S-08] Enforce HTTPS when TLS verification is enabled
        if ($this->verifySsl && $scheme === 'http') {
            throw new RuntimeException('Store-Doc returned an insecure (HTTP) download URL while TLS verification is enabled.');
        }

        // Allow HTTP only when the client itself uses HTTP
        if ($scheme === 'http' && ! str_starts_with(strtolower($this->apiUrl), 'http://')) {
            throw new RuntimeException('Store-Doc returned an unsafe download URL.');
        }

        return $url;
    }

    /**
     * Upload a file
     */
    public function upload(string $path, $contents, array $metadata = []): array
    {
        $path = $this->normalizePath($path);
        if (! is_string($contents) && ! is_resource($contents)) {
            throw new InvalidArgumentException('Upload contents must be a string or readable stream.');
        }
        if (is_resource($contents)) {
            $mode = stream_get_meta_data($contents)['mode'] ?? '';
            if (! str_starts_with($mode, 'r') && ! str_contains($mode, '+')) {
                throw new InvalidArgumentException('The upload stream is not readable.');
            }
        }

        $visibility = $metadata['visibility'] ?? 'private';
        if (! in_array($visibility, ['inherit', 'private', 'public', 'authenticated'], true)) {
            throw new InvalidArgumentException('The requested visibility is invalid.');
        }

        $data = $this->requestJson('POST', 'storage/upload', [
            'multipart' => [
                ['name' => 'bucket_id', 'contents' => $this->requireBucket()],
                ['name' => 'path', 'contents' => $path],
                ['name' => 'file', 'contents' => $contents, 'filename' => basename($path)],
                ['name' => 'visibility', 'contents' => $visibility],
            ],
        ], 'upload');

        if (! isset($data['data']) || ! is_array($data['data'])) {
            throw new RuntimeException('Store-Doc upload response did not contain object metadata.');
        }

        return $data;
    }

    /**
     * Download a file
     */
    public function download(string $objectId): string
    {
        return $this->request('GET', $this->objectUri($objectId, '/download'), [], 'download')
            ->getBody()->getContents();
    }

    /**
     * Delete a file
     */
    public function delete(string $objectId): bool
    {
        $this->request('DELETE', $this->objectUri($objectId), [], 'delete');

        return true;
    }

    /**
     * List objects in bucket.
     *
     * [M-01] Uses array_push spread instead of array_merge to avoid O(n²) copies.
     */
    public function listObjects(string $prefix = '', bool $recursive = false): array
    {
        $hasTrailingSlash = str_ends_with($prefix, '/');
        $prefix = $this->normalizePath($prefix, true);
        if ($hasTrailingSlash && $prefix !== '') {
            $prefix .= '/';
        }
        $items = [];
        $page = 1;
        $lastPage = 1;
        $bucket = rawurlencode($this->requireBucket());

        do {
            $data = $this->requestJson('GET', "storage/buckets/{$bucket}/objects", [
                'query' => [
                    'prefix' => $prefix,
                    'recursive' => $recursive ? '1' : '0',
                    'per_page' => 100,
                    'page' => $page,
                ],
            ], 'list objects');

            if (! isset($data['data']) || ! is_array($data['data'])) {
                throw new RuntimeException('Store-Doc returned an invalid object listing.');
            }
            foreach ($data['data'] as $object) {
                if (! is_array($object) ||
                    ! isset($object['id']) ||
                    ! is_string($object['key'] ?? $object['path'] ?? null)) {
                    throw new RuntimeException('Store-Doc returned invalid object metadata in a listing.');
                }
            }

            // [M-01] Spread push avoids creating a full copy of $items on each iteration
            array_push($items, ...$data['data']);
            $lastPage = max(1, (int) ($data['meta']['last_page'] ?? 1));
            if ($lastPage < $page || $lastPage > $this->maxListingPages) {
                throw new RuntimeException('Store-Doc returned invalid object listing pagination.');
            }
            $page++;
        } while ($page <= $lastPage);

        return $items;
    }

    protected function nullableMetadata(string $uri, string $operation): ?array
    {
        try {
            $data = $this->requestJson('GET', $uri, [], $operation);
        } catch (RuntimeException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof RequestException &&
                $previous->getResponse()?->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }

        return isset($data['data']) && is_array($data['data']) ? $data['data'] : null;
    }

    /**
     * Get object metadata
     */
    public function getObjectMetadata(string $objectId): ?array
    {
        return $this->nullableMetadata($this->objectUri($objectId), 'get object metadata');
    }

    public function updateObjectMetadata(string $objectId, array $metadata): array
    {
        $data = $this->requestJson('PATCH', $this->objectUri($objectId), [
            'json' => $metadata,
        ], 'update object metadata');

        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /**
     * Find object by path.
     *
     * [B-02] Paginates lazily and returns as soon as the exact match is found,
     *        instead of loading the entire prefix listing into memory first.
     */
    public function findObjectByPath(string $path): ?array
    {
        $normalized = $this->normalizePath($path);
        $bucket = rawurlencode($this->requireBucket());
        $page = 1;
        $lastPage = 1;

        do {
            $data = $this->requestJson('GET', "storage/buckets/{$bucket}/objects", [
                'query' => [
                    'prefix' => $normalized,
                    'recursive' => '0',
                    'per_page' => 100,
                    'page' => $page,
                ],
            ], 'find object by path');

            if (! isset($data['data']) || ! is_array($data['data'])) {
                return null;
            }

            foreach ($data['data'] as $object) {
                $objectPath = trim((string) ($object['key'] ?? $object['path'] ?? ''), '/');

                if ($objectPath === $normalized && isset($object['id'])) {
                    return $object;
                }
            }

            $lastPage = max(1, (int) ($data['meta']['last_page'] ?? 1));
            $page++;
        } while ($page <= $lastPage && $page <= $this->maxListingPages);

        return null;
    }

    /**
     * Check if object exists
     */
    public function exists(string $path): bool
    {
        return $this->findObjectByPath($path) !== null;
    }

    /**
     * Get object URL
     */
    public function getUrl(string $objectId): string
    {
        $data = $this->requestJson('GET', $this->objectUri($objectId, '/presigned-url'), [], 'generate download URL');

        return $this->validatePresignedUrl($data['data']['url'] ?? null);
    }

    /**
     * Generate a temporary URL with expiration
     *
     * @param  string  $objectId  Object UUID
     * @param  \DateTimeInterface  $expiration  Expiration time
     * @param  array  $options  Additional options (e.g., response-content-disposition)
     * @return string Temporary URL
     */
    public function getTemporaryUrl(string $objectId, \DateTimeInterface $expiration, array $options = []): string
    {
        $expiresInSeconds = $expiration->getTimestamp() - time();
        if ($expiresInSeconds <= 0) {
            throw new InvalidArgumentException('Temporary URL expiration must be in the future.');
        }
        $expiresInMinutes = (int) ceil($expiresInSeconds / 60);
        if ($expiresInMinutes > $this->maxTemporaryUrlMinutes) {
            throw new InvalidArgumentException('The requested temporary URL lifetime exceeds the configured maximum.');
        }

        $allowedOptions = [
            'response-content-disposition' => 'response-content-disposition',
            'ResponseContentDisposition' => 'response-content-disposition',
            'response-content-type' => 'response-content-type',
            'ResponseContentType' => 'response-content-type',
            'response-cache-control' => 'response-cache-control',
            'ResponseCacheControl' => 'response-cache-control',
            'response-content-encoding' => 'response-content-encoding',
            'ResponseContentEncoding' => 'response-content-encoding',
            'response-expires' => 'response-expires',
            'ResponseExpires' => 'response-expires',
        ];
        $safeOptions = [];
        foreach ($options as $name => $value) {
            if (! isset($allowedOptions[$name]) ||
                ! is_string($value) ||
                preg_match('/[\r\n\x00]/', $value)) {
                throw new InvalidArgumentException('Temporary URL options contain an unsupported or invalid value.');
            }
            $safeOptions[$allowedOptions[$name]] = $value;
        }

        $data = $this->requestJson('GET', $this->objectUri($objectId, '/presigned-url'), [
            'query' => ['expires_in' => $expiresInMinutes] + $safeOptions,
        ], 'generate temporary URL');

        return $this->validatePresignedUrl($data['data']['url'] ?? null);
    }

    /**
     * Download file as stream (for large files)
     *
     * @param  string  $objectId  Object UUID
     * @return resource Stream resource
     */
    public function downloadStream(string $objectId)
    {
        $response = $this->request('GET', $this->objectUri($objectId, '/download'), [
            'stream' => true,
        ], 'download stream');
        $stream = $response->getBody()->detach();

        if (! is_resource($stream)) {
            throw new RuntimeException('Store-Doc returned an invalid download stream.');
        }

        return $stream;
    }

    /**
     * Get file checksum/hash
     *
     * [M-02] Uses streaming hash (hash_update_stream) to avoid loading the
     *        entire file contents into a PHP string.
     *
     * @param  string  $objectId  Object UUID
     * @param  string  $algorithm  Algorithm (md5, sha1, sha256)
     * @return string|null Checksum hash or null if not available
     */
    public function getChecksum(string $objectId, string $algorithm = 'md5'): ?string
    {
        if (! in_array($algorithm, hash_algos(), true)) {
            throw new InvalidArgumentException('The requested checksum algorithm is not supported.');
        }

        $object = $this->getObjectMetadata($objectId);

        if (! $object) {
            return null;
        }

        $checksumField = $algorithm.'_hash';
        if (isset($object[$checksumField]) && is_string($object[$checksumField])) {
            return $object[$checksumField];
        }

        // [M-02] Stream the download and hash incrementally instead of
        //        loading the full file into memory with download().
        if (isset($object['size']) && (int) $object['size'] < 10 * 1024 * 1024) {
            $stream = $this->downloadStream($objectId);
            $ctx = hash_init($algorithm);
            hash_update_stream($ctx, $stream);
            fclose($stream);

            return hash_final($ctx);
        }

        return null;
    }

    /**
     * Get object download URL with specific response headers
     * Useful for forcing download with specific filename
     *
     * @param  string  $objectId  Object UUID
     * @param  string|null  $filename  Optional filename for Content-Disposition
     * @param  bool  $inline  If true, display inline; if false, force download
     * @return string Download URL with query parameters
     */
    public function getDownloadUrl(string $objectId, ?string $filename = null, bool $inline = false): string
    {
        $options = [];

        if ($filename) {
            $disposition = $inline ? 'inline' : 'attachment';
            if (preg_match('/[\r\n\x00]/', $filename)) {
                throw new InvalidArgumentException('The download filename is invalid.');
            }
            $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'download';
            $options['response-content-disposition'] = "{$disposition}; filename=\"{$fallback}\"; filename*=UTF-8''".rawurlencode($filename);
        }

        $data = $this->requestJson('GET', $this->objectUri($objectId, '/presigned-url'), [
            'query' => $options,
        ], 'generate download URL');

        return $this->validatePresignedUrl($data['data']['url'] ?? null);
    }

    /**
     * Create a new bucket
     *
     * @param  array  $params  Bucket configuration
     *                         - name (required): Bucket name (3-63 chars, lowercase, alphanumeric and hyphens)
     *                         - description (optional): Bucket description
     *                         - region (optional): Region (us-east-1, us-west-1, us-west-2, eu-west-1, eu-central-1, ap-southeast-1, ap-northeast-1)
     *                         - visibility (optional): private|public|authenticated (default: private)
     *                         - versioning_enabled (optional): Enable versioning (default: false)
     *                         - encryption_enabled (optional): Enable encryption (default: false)
     *                         - cors_rules (optional): Array of CORS rules
     *                         - tags (optional): Array of tags
     */
    public function createBucket(array $params): array
    {
        $data = $this->requestJson('POST', 'storage/buckets', [
            'json' => $params,
        ], 'create bucket');

        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /**
     * List all buckets
     */
    public function listBuckets(array $filters = []): array
    {
        return $this->listPaginated('storage/buckets', $filters, 'list buckets');
    }

    /**
     * Get bucket details
     */
    public function getBucket(string $bucketId): ?array
    {
        return $this->nullableMetadata('storage/buckets/'.rawurlencode($bucketId), 'get bucket');
    }

    /**
     * Delete a bucket
     */
    public function deleteBucket(string $bucketId): bool
    {
        $this->request('DELETE', 'storage/buckets/'.rawurlencode($bucketId), [], 'delete bucket');

        return true;
    }

    /**
     * Create a new folder
     *
     * @param  array  $params  Folder configuration
     *                         - bucket_id (required): UUID of the bucket
     *                         - name (required): Folder name (cannot contain: / \ : * ? " < > |)
     *                         - parent_id (optional): UUID of parent folder
     *                         - visibility (optional): inherit|private|public|authenticated (default: inherit)
     *                         - metadata (optional): Array of custom metadata
     */
    public function createFolder(array $params): array
    {
        $data = $this->requestJson('POST', 'storage/folders', [
            'json' => $params,
        ], 'create folder');

        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /**
     * List folders in a bucket
     */
    public function listFolders(string $bucketId, ?string $parentId = null): array
    {
        return $this->listPaginated(
            'storage/buckets/'.rawurlencode($bucketId).'/folders',
            array_filter(['parent_id' => $parentId]),
            'list folders'
        );
    }

    /**
     * Get folder details
     */
    public function getFolder(string $folderId): ?array
    {
        return $this->nullableMetadata('storage/folders/'.rawurlencode($folderId), 'get folder');
    }

    /**
     * Delete a folder
     */
    public function deleteFolder(string $folderId): bool
    {
        $this->request('DELETE', 'storage/folders/'.rawurlencode($folderId), [], 'delete folder');

        return true;
    }

    /**
     * Fetch one page of objects matching a prefix (for incremental deletion).
     *
     * [M-04] Returns a single page so callers can process/delete objects
     *        before requesting the next page, limiting peak memory usage.
     */
    public function findObjectsByPrefix(string $prefix): array
    {
        $bucket = rawurlencode($this->requireBucket());

        $data = $this->requestJson('GET', "storage/buckets/{$bucket}/objects", [
            'query' => [
                'prefix' => $prefix,
                'recursive' => '1',
                'per_page' => 100,
                'page' => 1,
            ],
        ], 'list objects by prefix');

        if (! isset($data['data']) || ! is_array($data['data'])) {
            return [];
        }

        return $data['data'];
    }

    /**
     * Paginate through a listing endpoint and return all items.
     *
     * [M-01] Uses array_push spread instead of array_merge to avoid O(n²) copies.
     */
    protected function listPaginated(string $uri, array $filters, string $operation): array
    {
        $items = [];
        $page = 1;
        $lastPage = 1;

        do {
            $data = $this->requestJson('GET', $uri, [
                'query' => array_merge($filters, ['per_page' => 100, 'page' => $page]),
            ], $operation);
            if (! isset($data['data']) || ! is_array($data['data'])) {
                throw new RuntimeException("Store-Doc returned an invalid {$operation} response.");
            }

            // [M-01] Spread push avoids creating a full copy of $items on each iteration
            array_push($items, ...$data['data']);
            $lastPage = max(1, (int) ($data['meta']['last_page'] ?? 1));
            if ($lastPage < $page || $lastPage > $this->maxListingPages) {
                throw new RuntimeException("Store-Doc returned invalid {$operation} pagination.");
            }
            $page++;
        } while ($page <= $lastPage);

        return $items;
    }
}
