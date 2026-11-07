<?php

namespace StoreDoc\Laravel\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;

/**
 * StoreDoc Filesystem Adapter - Fully Compatible with Laravel Storage
 *
 * This class extends FilesystemAdapter to provide 100% compatibility
 * with Laravel's Storage facade while using StoreDoc backend.
 */
class StoreDocFilesystem extends FilesystemAdapter
{
    protected StoreDocAdapter $storeDocAdapter;

    protected StoreDocClient $client;

    /** [M-03] Maximum file size (bytes) for in-memory prepend/append operations. */
    protected const MAX_INLINE_SIZE = 50 * 1024 * 1024; // 50 MB

    public function __construct(FilesystemOperator $driver, StoreDocAdapter $adapter, array $config)
    {
        parent::__construct($driver, $adapter, $config);

        $this->storeDocAdapter = $adapter;
        $this->client = $adapter->getClient();
    }

    /**
     * Get the URL for the file at the given path.
     *
     * @param  string  $path
     * @return string
     */
    public function url($path)
    {
        if (isset($this->config['url'])) {
            return $this->concatPathToUrl($this->config['url'], $path);
        }

        $object = $this->storeDocAdapter->getObjectByPath($path);
        if (! $object || ! isset($object['id'])) {
            // [S-05] Do not interpolate user-supplied path into exception messages
            throw new \RuntimeException('The requested file could not be found.');
        }

        foreach ([
            $object['urls']['public'] ?? null,
            $object['urls']['cdn'] ?? null,
            $object['public_url'] ?? null,
            $object['urls']['preview'] ?? null,
            $object['urls']['download'] ?? null,
        ] as $url) {
            if (is_string($url) && $url !== '') {
                return $this->client->validatePresignedUrl($url);
            }
        }

        return $this->client->getTemporaryUrl($object['id'], now()->addHour());
    }

    /**
     * Get a temporary URL for the file at the given path.
     *
     * @param  string  $path
     * @param  \DateTimeInterface  $expiration
     * @return string
     *
     * @throws \RuntimeException
     */
    public function temporaryUrl($path, $expiration, array $options = [])
    {
        $object = $this->storeDocAdapter->getObjectByPath($path);

        if (! $object || ! isset($object['id'])) {
            // [S-05] Do not interpolate user-supplied path into exception messages
            throw new \RuntimeException('The requested file could not be found.');
        }

        return $this->client->getTemporaryUrl($object['id'], $expiration, $options);
    }

    /**
     * Get the checksum for a file.
     *
     * @param  string  $path
     * @return string|false
     */
    public function checksum($path, array $options = [])
    {
        $object = $this->storeDocAdapter->getObjectByPath($path);
        if (! $object || ! isset($object['id'])) {
            return false;
        }

        $algorithm = $options['checksum_algorithm'] ?? 'md5';

        return $this->client->getChecksum($object['id'], $algorithm) ?? false;
    }

    /**
     * Concatenate a path to a URL.
     *
     * [S-07] Validates path segments to prevent directory-traversal when a
     *        custom base URL is configured.
     *
     * @param  string  $url
     * @param  string  $path
     * @return string
     */
    protected function concatPathToUrl($url, $path)
    {
        // [S-07] Guard against path traversal via dot segments
        $normalized = trim($path, '/');
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Storage paths cannot contain dot segments.');
            }
        }

        return rtrim($url, '/').'/'.ltrim($path, '/');
    }

    /**
     * Get the contents of a file as decoded JSON.
     *
     * [S-06] Always enables JSON_THROW_ON_ERROR so silent decode failures are
     *        surfaced as exceptions. Only safe additional flags are permitted.
     *
     * @param  string  $path
     * @param  int  $flags
     * @return array|null
     */
    public function json($path, $flags = 0)
    {
        $content = $this->get($path);

        if ($content === null) {
            return null;
        }

        // [S-06] Enforce JSON_THROW_ON_ERROR and restrict allowed flags
        $allowedFlags = JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR;
        $flags = ($flags & $allowedFlags) | JSON_THROW_ON_ERROR;

        return json_decode($content, true, 512, $flags);
    }

    /**
     * Prepend to a file.
     *
     * [M-03] Checks file size before loading into memory to prevent OOM on
     *        large files.
     *
     * @param  string  $path
     * @param  string  $data
     * @param  string  $separator
     * @return int|bool
     */
    public function prepend($path, $data, $separator = PHP_EOL)
    {
        if ($this->exists($path)) {
            // [M-03] Bail out early for files that are too large for in-memory operations
            $sizeAttrs = $this->storeDocAdapter->fileSize($path);
            if ($sizeAttrs->fileSize() !== null && $sizeAttrs->fileSize() > self::MAX_INLINE_SIZE) {
                throw new \RuntimeException(
                    'The file exceeds the maximum size for in-memory prepend ('.self::MAX_INLINE_SIZE.' bytes). Use streaming instead.'
                );
            }

            return $this->put($path, $data.$separator.$this->get($path));
        }

        return $this->put($path, $data);
    }

    /**
     * Append to a file.
     *
     * [M-03] Checks file size before loading into memory to prevent OOM on
     *        large files.
     *
     * @param  string  $path
     * @param  string  $data
     * @param  string  $separator
     * @return int|bool
     */
    public function append($path, $data, $separator = PHP_EOL)
    {
        if ($this->exists($path)) {
            // [M-03] Bail out early for files that are too large for in-memory operations
            $sizeAttrs = $this->storeDocAdapter->fileSize($path);
            if ($sizeAttrs->fileSize() !== null && $sizeAttrs->fileSize() > self::MAX_INLINE_SIZE) {
                throw new \RuntimeException(
                    'The file exceeds the maximum size for in-memory append ('.self::MAX_INLINE_SIZE.' bytes). Use streaming instead.'
                );
            }

            return $this->put($path, $this->get($path).$separator.$data);
        }

        return $this->put($path, $data);
    }

    /**
     * Get a preview URL for the file (inline display).
     *
     * @param  string  $path
     * @param  string|null  $filename
     * @return string
     *
     * @throws \RuntimeException
     */
    public function previewUrl($path, $filename = null)
    {
        $object = $this->storeDocAdapter->getObjectByPath($path);

        if (! $object || ! isset($object['id'])) {
            // [S-05] Do not interpolate user-supplied path into exception messages
            throw new \RuntimeException('The requested file could not be found.');
        }

        // Use original name if not provided
        $name = $filename ?? ($object['original_name'] ?? basename($path));

        return $this->client->getDownloadUrl($object['id'], $name, true);
    }

    /**
     * Get a download URL for the file (force download).
     *
     * @param  string  $path
     * @param  string|null  $filename
     * @return string
     *
     * @throws \RuntimeException
     */
    public function downloadUrl($path, $filename = null)
    {
        $object = $this->storeDocAdapter->getObjectByPath($path);

        if (! $object || ! isset($object['id'])) {
            // [S-05] Do not interpolate user-supplied path into exception messages
            throw new \RuntimeException('The requested file could not be found.');
        }

        // Use original name if not provided
        $name = $filename ?? ($object['original_name'] ?? basename($path));

        return $this->client->getDownloadUrl($object['id'], $name, false);
    }

    /**
     * Create a streamed response for a file.
     *
     * [B-07] Verifies that readStream() returns a valid resource before
     *        attempting fpassthru/fclose.
     *
     * @param  string  $path
     * @param  string|null  $name
     * @param  string|null  $disposition
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function response($path, $name = null, array $headers = [], $disposition = 'inline')
    {
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse;

        $filename = $name ?? basename($path);

        $disposition = $response->headers->makeDisposition(
            $disposition, $filename, $this->fallbackName($filename)
        );

        // We omit Content-Length to avoid mismatch errors if the stream size
        // differs from metadata size (e.g. due to compression or API behavior).
        $response->headers->replace($headers + [
            'Content-Type' => $this->mimeType($path),
            'Content-Disposition' => $disposition,
        ]);

        $response->setCallback(function () use ($path) {
            $stream = $this->readStream($path);

            // [B-07] Verify the stream is a valid resource before use
            if (! is_resource($stream)) {
                throw new \RuntimeException('Failed to open a download stream for the requested file.');
            }

            fpassthru($stream);
            fclose($stream);
        });

        return $response;
    }

    /**
     * Get the fallback filename for the Content-Disposition header.
     *
     * @param  string  $name
     * @return string
     */
    protected function fallbackName($name)
    {
        return str_replace('%', '', \Illuminate\Support\Str::ascii($name));
    }
}
