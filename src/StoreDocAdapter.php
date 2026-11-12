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

    protected StoreDocCache $cache;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = $this->createClient($config);
        $this->cache = new StoreDocCache($config);
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

    /**
     * Get the underlying StoreDoc cache manager.
     */
    public function getCache(): StoreDocCache
    {
        return $this->cache;
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
            $this->cache->forgetPath($this->config['bucket'] ?? '', $path);
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
            $this->cache->forgetPath($this->config['bucket'] ?? '', $path);
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
            $this->cache->forgetPath($this->config['bucket'] ?? '', $path);
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

                $deleted = 0;
                foreach ($data as $object) {
                    $objectPath = trim((string) ($object['key'] ?? $object['path'] ?? ''), '/');
                    if ($objectPath !== '' && str_starts_with($objectPath, $prefix) && isset($object['id'])) {
                        $this->client->delete($object['id']);
                        $deleted++;
                    }
                }

                // Nothing could be deleted, so the listing would never change.
                // Stop instead of looping until the upper bound is reached.
                if ($deleted === 0) {
                    break;
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

    /**
     * List the contents of a directory.
     *
     * [B-08] The Store-Doc API only exposes a flat object listing, so
     *        directories are derived from the object paths. A shallow listing
     *        therefore only returns the direct children (files) and the
     *        immediate sub-directories, while a deep listing returns every
     *        file together with all of its parent directories.
     *
     *        The previous implementation treated the last segment of an object
     *        path as a directory, so nested files were reported as folders
     *        and never returned as files.
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $directory = trim($path, '/');
        $prefix = $directory === '' ? '' : $directory.'/';

        // Always request the whole subtree: the API has no dedicated directory
        // listing, so the directory tree has to be derived locally.
        $objects = $this->client->listObjects($prefix, true);
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

            $timestamp = isset($object['updated_at']) ? strtotime((string) $object['updated_at']) : null;
            $timestamp = $timestamp === false ? null : $timestamp;

            $isFolder = ($object['type'] ?? null) === 'folder';

            $segments = explode('/', $relativePath);
            $entryName = array_pop($segments);
            $ancestorDepth = count($segments);

            if (! $deep) {
                // Shallow listing: direct files only, plus the immediate
                // sub-directories derived from deeper objects.
                if ($ancestorDepth === 0) {
                    if ($isFolder) {
                        $folderPath = $directory === '' ? $entryName : $directory.'/'.$entryName;
                        if (! isset($seenDirectories[$folderPath])) {
                            $seenDirectories[$folderPath] = true;
                            yield new DirectoryAttributes($folderPath, null, $timestamp);
                        }

                        continue;
                    }

                    yield new FileAttributes(
                        $directory === '' ? $relativePath : $directory.'/'.$relativePath,
                        $object['size'] ?? null,
                        $object['visibility'] ?? 'private',
                        $timestamp,
                        $object['mime_type'] ?? null
                    );

                    continue;
                }

                $immediate = $segments[0];
                $folderPath = $directory === '' ? $immediate : $directory.'/'.$immediate;
                if (! isset($seenDirectories[$folderPath])) {
                    $seenDirectories[$folderPath] = true;
                    yield new DirectoryAttributes($folderPath, null, $timestamp);
                }

                continue;
            }

            // Deep listing: yield every parent directory of the entry.
            for ($depth = 1; $depth <= $ancestorDepth; $depth++) {
                $directoryPath = implode('/', array_slice($segments, 0, $depth));
                $directoryPath = $directory === '' ? $directoryPath : $directory.'/'.$directoryPath;
                if (! isset($seenDirectories[$directoryPath])) {
                    $seenDirectories[$directoryPath] = true;
                    yield new DirectoryAttributes($directoryPath, null, $timestamp);
                }
            }

            if ($isFolder) {
                $folderPath = $directory === '' ? $relativePath : $directory.'/'.$relativePath;
                if (! isset($seenDirectories[$folderPath])) {
                    $seenDirectories[$folderPath] = true;
                    yield new DirectoryAttributes($folderPath, null, $timestamp);
                }

                continue;
            }

            yield new FileAttributes(
                $directory === '' ? $relativePath : $directory.'/'.$relativePath,
                $object['size'] ?? null,
                $object['visibility'] ?? 'private',
                $timestamp,
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
            $bucket = $this->config['bucket'] ?? '';
            $this->cache->forgetPath($bucket, $source);
            $this->cache->forgetPath($bucket, $destination);
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
            $this->cache->forgetPath($this->config['bucket'] ?? '', $destination);
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
        $bucket = $this->config['bucket'] ?? '';
        $cached = $this->cache->getObject($bucket, $path);
        if ($cached !== null) {
            return $cached;
        }

        $object = $this->client->findObjectByPath($path);
        if ($object !== null) {
            $this->cache->putObject($bucket, $path, $object);
        }

        return $object;
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
