<?php

namespace StoreDoc\Laravel\Storage\Tests;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use StoreDoc\Laravel\Storage\StoreDocClient;

/**
 * Collects the requests that were sent through the mocked handler.
 */
final class RequestRecorder
{
    /**
     * @var array<int, RequestInterface>
     */
    public array $requests = [];
}

/**
 * Regression tests for the object lookup by path.
 *
 * The Store-Doc API lists objects by a bucket relative key. A lookup for a
 * single file therefore sends the exact path as the prefix. A server that
 * honours "recursive=0" must still return the object whose key equals the
 * prefix, otherwise every path based operation (url, get, exists, delete,
 * put) fails.
 */
final class PathLookupTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $responses
     * @return array{0: HandlerStack, 1: RequestRecorder}
     */
    private function handler(array $responses): array
    {
        $recorder = new RequestRecorder();
        $queue = $responses;

        $stack = HandlerStack::create(function (RequestInterface $request) use ($recorder, &$queue) {
            $recorder->requests[] = $request;

            $response = array_shift($queue) ?? ['data' => [], 'meta' => ['last_page' => 1]];

            return \GuzzleHttp\Promise\Create::promiseFor(
                new Response(200, [], json_encode($response))
            );
        });

        return [$stack, $recorder];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function client(HandlerStack $stack, array $overrides = []): StoreDocClient
    {
        return new TestableStoreDocClient(array_merge([
            'api_url' => 'https://storage.example.test/api',
            'access_key_id' => 'access-id',
            'secret_access_key' => 'secret-key',
            'bucket' => 'bucket-id',
            'auth_mode' => 'basic',
            'max_retries' => 0,
        ], $overrides), $stack);
    }

    #[Test]
    public function an_exact_path_is_found_in_a_shallow_listing(): void
    {
        [$stack] = $this->handler([[
            'data' => [['id' => 'abc', 'key' => 'avatars/photo.png']],
            'meta' => ['last_page' => 1],
        ]]);

        $object = $this->client($stack)->findObjectByPath('avatars/photo.png');

        $this->assertNotNull($object);
        $this->assertSame('abc', $object['id']);
    }

    #[Test]
    public function the_recursive_listing_is_used_when_the_shallow_lookup_returns_nothing(): void
    {
        [$stack, $recorder] = $this->handler([
            ['data' => [], 'meta' => ['last_page' => 1]],
            [
                'data' => [['id' => 'abc', 'key' => 'avatars/photo.png']],
                'meta' => ['last_page' => 1],
            ],
        ]);

        $object = $this->client($stack)->findObjectByPath('avatars/photo.png');

        $this->assertNotNull($object);
        $this->assertSame('abc', $object['id']);
        $this->assertCount(2, $recorder->requests);
        $this->assertStringContainsString('recursive=0', (string) $recorder->requests[0]->getUri());
        $this->assertStringContainsString('recursive=1', (string) $recorder->requests[1]->getUri());
    }

    #[Test]
    public function the_recursive_listing_is_not_requested_when_the_shallow_lookup_matches(): void
    {
        [$stack, $recorder] = $this->handler([[
            'data' => [['id' => 'abc', 'key' => 'avatars/photo.png']],
            'meta' => ['last_page' => 1],
        ]]);

        $this->client($stack)->findObjectByPath('avatars/photo.png');

        $this->assertCount(1, $recorder->requests);
    }

    #[Test]
    public function a_shallow_listing_keeps_an_object_whose_key_equals_the_prefix(): void
    {
        [$stack] = $this->handler([[
            'data' => [
                ['id' => '1', 'key' => 'avatars/photo.png'],
                ['id' => '2', 'key' => 'avatars/photo.png.bak'],
            ],
            'meta' => ['last_page' => 1],
        ]]);

        $objects = $this->client($stack)->listObjects('avatars/photo.png', false);

        $this->assertCount(1, $objects);
        $this->assertSame('avatars/photo.png', $objects[0]['key']);
    }
}
