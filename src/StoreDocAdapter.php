<?php

namespace StoreDoc\Laravel\Storage;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;

class StoreDocAdapter implements FilesystemAdapter
{
    protected StoreDocClient $client;

    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = $this->createClient($config);
    }

    protected function createClient(array $config): StoreDocClient
    {
        return new StoreDocClient($config);
    }

    /**
     * Get the underlying StoreDoc client.
     */
    public function getClient(): StoreDocClient
    {
        return $this->client;
    }

    public function fileExists(string $path): bool
    {
        return $this->getObjectByPath($path) !== null;
    }

    public function directoryExists(string $path): bool
    {
        $prefix = trim($path, '/');
        if ($prefix === '') {
            return $this->client->listObjects('', false) !== [];
        }

        foreach ($this->client->listObjects($prefix.'/', false) as $object) {
            if (str_starts_with(trim((string) ($object['key'] ?? $object['path'] ?? ''), '/'), $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $visibility = $config->get('visibility', 'private');
            $this->client->upload($path, $contents, ['visibility' => $visibility]);
        } catch (\Exception $e) {
            throw UnableToWriteFile::atLocation($path, 'Store-Doc write failed.', $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $visibility = $config->get('visibility', 'private');
            if (! is_resource($contents)) {
                throw new \InvalidArgumentException('Upload contents must be a stream resource.');
            }

            $this->client->upload($path, $contents, ['visibility' => $visibility]);
        } catch (\Exception $e) {
            throw UnableToWriteFile::atLocation($path, 'Store-Doc stream write failed.', $e);
        }
    }

    public function read(string $path): string
    {
        try {
            $object = $this->client->findObjectByPath($path);
            if (! $object || ! isset($object['id'])) {
                // [S-05] Do not interpolate user-supplied path into exception messages
                throw new \RuntimeException('The requested file could not be found.');
            }

            return $this->client->download($object['id']);
        } catch (\Exception $e) {
            throw UnableToReadFile::fromLocation($path, 'Store-Doc read failed.', $e);
        }
    }

    public function readStream(string $path)
    {
        try {
            $object = $this->getObjectByPath($path);
            if (! $object || ! isset($object['id'])) {
                // [S-05] Do not interpolate user-supplied path into exception messages
                throw new \RuntimeException('The requested file could not be found.');
            }

            $stream = $this->client->downloadStream($object['id']);
            if (! is_resource($stream)) {
                throw new \RuntimeException('Invalid stream resource');
            }

            return $stream;
        } catch (\Exception $e) {
            throw UnableToReadFile::fromLocation($path, 'Store-Doc stream read failed.', $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $object = $this->client->findObjectByPath($path);
            if ($object && isset($object['id'])) {
                $this->client->delete($object['id']);
            }
        } catch (\Exception $e) {
            throw UnableToDeleteFile::atLocation($path, 'Store-Doc delete failed.', $e);
        }
    }

    /**
     * Delete all objects under a directory prefix.
     *
     * [B-05] Refuses to delete the entire bucket root when an empty path is given.
     * [M-04] Deletes objects page-by-page instead of loading the full listing
     *        into memory first.
     */
    public function deleteDirectory(string $path): void
    {
        try {
            $directory = trim($path, '/');

            // [B-05] Guard against accidental full-bucket wipe
            if ($directory === '') {
                throw new \RuntimeException(
                    'Refusing to delete the entire bucket root. Specify a non-empty directory path.'
                );
            }

            $prefix = $directory.'/';

            // [M-04] Paginate and delete per page to limit peak memory usage.
            //        Always request page 1 because earlier pages shrink as we delete.
            $safety = 0;
            $maxPages = 10000; // absolute upper bound to prevent infinite loops

            do {
                $data = $this->client->findObjectsByPrefix($prefix);

                if (empty($data)) {
                    break;
                }

                foreach ($data as $object) {
                    $objectPath = trim((string) ($object['key'] ?? $object['path'] ?? ''), '/');
                    if ($objectPath !== '' && str_starts_with($objectPath, $prefix) && isset($object['id'])) {
                        $this->client->delete($object['id']);
                    }
                }

                $safety++;
            } while ($safety < $maxPages);
        } catch (\Exception $e) {
            throw UnableToDeleteDirectory::atLocation($path, 'Store-Doc directory delete failed.', $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Store-Doc doesn't require explicit directory creation
        // Directories are implicit based on file paths
    }

    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $object = $this->getObjectByPath($path);
            if (! $object || ! isset($object['id'])) {
                // [S-05] Do not interpolate user-supplied path into exception messages
                throw new \RuntimeException('The requested file could not be found.');
            }

            $this->client->updateObjectMetadata($object['id'], ['visibility' => $visibility]);
        } catch (\Exception $e) {
            throw UnableToSetVisibility::atLocation($path, 'Store-Doc visibility update failed.', $e);
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $object = $this->metadataForPath($path);
        $visibility = $object['visibility'] ?? 'private';

        return new FileAttributes(
            $path,
            null,
            $visibility
        );
    }

    public function mimeType(string $path): FileAttributes
    {
        $object = $this->metadataForPath($path);

        return new FileAttributes(
            $path,
            null,
            null,
            null,
            $object['mime_type'] ?? 'application/octet-stream'
        );
    }

    public function lastModified(string $path): FileAttributes
    {
        $object = $this->metadataForPath($path);
        $timestamp = isset($object['updated_at']) ? strtotime($object['updated_at']) : null;
        $timestamp = $timestamp === false ? null : $timestamp;

        return new FileAttributes(
            $path,
            null,
            null,
            $timestamp
        );
    }

    /**
     * [B-06] Returns null (not 0) when the API does not provide a file size,
     *        so callers can distinguish "unknown" from "empty".
     */
    public function fileSize(string $path): FileAttributes
    {
        $object = $this->metadataForPath($path);

        return new FileAttributes(
            $path,
            isset($object['size']) ? (int) $object['size'] : null
        );
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $directory = trim($path, '/');
        $prefix = $directory === '' ? '' : $directory.'/';
        $objects = $this->client->listObjects($prefix, $deep);
        $seenDirectories = [];

        foreach ($objects as $object) {
            $objectPath = (string) ($object['path'] ?? $object['key'] ?? '');
            if ($objectPath === '') {
                continue;
            }

            $objectPath = trim($objectPath, '/');
            if ($prefix !== '' && ! str_starts_with($objectPath, $prefix)) {
                continue;
            }

            $relativePath = $prefix === '' ? $objectPath : substr($objectPath, strlen($prefix));
            if ($relativePath === '') {
                continue;
            }

            $segments = explode('/', $relativePath);
            $directoryDepth = count($segments) - 1;
            if ($directoryDepth > 0 || ($object['type'] ?? null) === 'folder') {
                $directoryDepth = $deep ? max($directoryDepth, count($segments)) : 1;
                for ($depth = 1; $depth <= $directoryDepth; $depth++) {
                    $directoryPath = implode('/', array_slice($segments, 0, $depth));
                    $directoryPath = $directory === '' ? $directoryPath : $directory.'/'.$directoryPath;
                    if (! isset($seenDirectories[$directoryPath])) {
                        $seenDirectories[$directoryPath] = true;
                        $timestamp = isset($object['updated_at']) ? strtotime($object['updated_at']) : null;
                        yield new DirectoryAttributes($directoryPath, null, $timestamp === false ? null : $timestamp);
                    }
                }

                continue;
            }

            $timestamp = isset($object['updated_at']) ? strtotime($object['updated_at']) : null;
            yield new FileAttributes(
                $directory === '' ? $relativePath : $directory.'/'.$relativePath,
                $object['size'] ?? null,
                $object['visibility'] ?? 'private',
                $timestamp === false ? null : $timestamp,
                $object['mime_type'] ?? null
            );
        }
    }

    /**
     * Move a file from one path to another.
     *
     * [B-01] If the source deletion fails after a successful write, the
     *        destination is rolled back (deleted) so we never silently
     *        leave duplicates behind.
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $stream = $this->readStream($source);
        try {
            $this->writeStream($destination, $stream, $config);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // [B-01] If delete-source fails, roll back the destination write
        try {
            $this->delete($source);
        } catch (\Exception $e) {
            try {
                $this->delete($destination);
            } catch (\Exception) {
                // Best-effort rollback; the original error is more important.
            }

            throw $e;
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $stream = $this->readStream($source);
        try {
            $this->writeStream($destination, $stream, $config);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Get object by path, checking cache first
     */
    public function getObjectByPath(string $path): ?array
    {
        return $this->client->findObjectByPath($path);
    }

    /**
     * [S-05] Internal helper — throws a generic message without interpolating
     *        the user-supplied path.
     */
    protected function metadataForPath(string $path): array
    {
        $object = $this->getObjectByPath($path);
        if (! $object) {
            throw new \RuntimeException('The requested file could not be found.');
        }

        return $object;
    }

    public function publicUrl(string $path, Config $config): string
    {
        $object = $this->getObjectByPath($path);

        if (! $object || ! isset($object['id'])) {
            // [S-05] Do not interpolate user-supplied path into exception messages
            throw new \RuntimeException('The requested file could not be found.');
        }

        return $this->client->getUrl($object['id']);
    }
}
