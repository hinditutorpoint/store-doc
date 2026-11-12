<?php

namespace StoreDoc\Laravel\Storage\Tests;

use StoreDoc\Laravel\Storage\StoreDocClient;

/**
 * In-memory double for {@see StoreDocClient}.
 *
 * The parent constructor is intentionally not called: no HTTP client is
 * created and no credentials are required for the adapter tests.
 */
class FakeStoreDocClient extends StoreDocClient
{
    /**
     * @var array<int, array{prefix: string, recursive: bool}>
     */
    public array $listCalls = [];

    /**
     * @var array<int, string>
     */
    public array $deleted = [];

    /**
     * @param  array<int, array<string, mixed>>  $objects
     */
    public function __construct(protected array $objects = [])
    {
    }

    public function listObjects(string $prefix = '', bool $recursive = false): array
    {
        $this->listCalls[] = ['prefix' => $prefix, 'recursive' => $recursive];

        $normalized = trim($prefix, '/');
        $base = $normalized === '' ? '' : $normalized.'/';

        return array_values(array_filter($this->objects, function (array $object) use ($base): bool {
            $key = trim((string) ($object['key'] ?? $object['path'] ?? ''), '/');

            if ($base === '') {
                return $key !== '';
            }

            return str_starts_with($key, $base);
        }));
    }

    public function findObjectsByPrefix(string $prefix): array
    {
        return $this->listObjects($prefix, true);
    }

    public function findObjectByPath(string $path): ?array
    {
        $normalized = trim($path, '/');

        foreach ($this->objects as $object) {
            $key = trim((string) ($object['key'] ?? $object['path'] ?? ''), '/');
            if ($key === $normalized) {
                return $object;
            }
        }

        return null;
    }

    public function delete(string $objectId): bool
    {
        $this->deleted[] = $objectId;

        $this->objects = array_values(array_filter(
            $this->objects,
            fn (array $object): bool => ($object['id'] ?? null) !== $objectId
        ));

        return true;
    }
}
