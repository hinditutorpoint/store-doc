<?php

namespace StoreDoc\Laravel\Storage\Tests;

use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StoreDoc\Laravel\Storage\StoreDocAdapter;

final class ListContentsTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function objects(): array
    {
        return [
            ['id' => '1', 'key' => 'top.txt', 'size' => 12, 'mime_type' => 'text/plain'],
            ['id' => '2', 'key' => 'a/b.txt', 'size' => 22, 'mime_type' => 'text/plain'],
            ['id' => '3', 'key' => 'a/c/d.txt', 'size' => 32, 'mime_type' => 'text/plain'],
            ['id' => '4', 'key' => 'a/c/e.png', 'size' => 42, 'mime_type' => 'image/png'],
        ];
    }

    private function adapter(): StoreDocAdapter
    {
        return new FakeAdapter(
            [
                'api_url' => 'https://storage.example.test/api',
                'access_key_id' => 'access-id',
                'secret_access_key' => 'secret-key',
                'bucket' => 'bucket-id',
                'auth_mode' => 'basic',
            ],
            new FakeStoreDocClient($this->objects())
        );
    }

    /**
     * @param  iterable<int, mixed>  $contents
     * @return array<int, array{path: string, type: string}>
     */
    private function flatten(iterable $contents): array
    {
        $result = [];

        foreach ($contents as $entry) {
            if ($entry instanceof FileAttributes) {
                $result[] = ['path' => $entry->path(), 'type' => 'file'];
            } elseif ($entry instanceof DirectoryAttributes) {
                $result[] = ['path' => $entry->path(), 'type' => 'dir'];
            }
        }

        return $result;
    }

    #[Test]
    public function deep_listing_returns_nested_files_as_files(): void
    {
        $entries = $this->flatten($this->adapter()->listContents('', true));

        $this->assertSame([
            ['path' => 'top.txt', 'type' => 'file'],
            ['path' => 'a', 'type' => 'dir'],
            ['path' => 'a/b.txt', 'type' => 'file'],
            ['path' => 'a/c', 'type' => 'dir'],
            ['path' => 'a/c/d.txt', 'type' => 'file'],
            ['path' => 'a/c/e.png', 'type' => 'file'],
        ], $entries);
    }

    #[Test]
    public function shallow_listing_returns_only_direct_files_and_immediate_directories(): void
    {
        $entries = $this->flatten($this->adapter()->listContents('', false));

        $this->assertSame([
            ['path' => 'top.txt', 'type' => 'file'],
            ['path' => 'a', 'type' => 'dir'],
        ], $entries);
    }

    #[Test]
    public function deep_listing_of_a_sub_directory_keeps_the_directory_prefix(): void
    {
        $entries = $this->flatten($this->adapter()->listContents('a', true));

        $this->assertSame([
            ['path' => 'a/b.txt', 'type' => 'file'],
            ['path' => 'a/c', 'type' => 'dir'],
            ['path' => 'a/c/d.txt', 'type' => 'file'],
            ['path' => 'a/c/e.png', 'type' => 'file'],
        ], $entries);
    }

    #[Test]
    public function shallow_listing_of_a_sub_directory_does_not_descend_into_it(): void
    {
        $entries = $this->flatten($this->adapter()->listContents('a', false));

        $this->assertSame([
            ['path' => 'a/b.txt', 'type' => 'file'],
            ['path' => 'a/c', 'type' => 'dir'],
        ], $entries);
    }

    #[Test]
    public function the_whole_subtree_is_requested_because_the_api_has_no_folder_listing(): void
    {
        $client = new FakeStoreDocClient($this->objects());
        $adapter = new FakeAdapter(
            [
                'api_url' => 'https://storage.example.test/api',
                'access_key_id' => 'access-id',
                'secret_access_key' => 'secret-key',
                'bucket' => 'bucket-id',
            ],
            $client
        );

        iterator_to_array($adapter->listContents('a', false));

        $this->assertCount(1, $client->listCalls);
        $this->assertSame('a/', $client->listCalls[0]['prefix']);
        $this->assertTrue($client->listCalls[0]['recursive']);
    }
}
