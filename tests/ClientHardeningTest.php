<?php

namespace StoreDoc\Laravel\Storage\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StoreDoc\Laravel\Storage\StoreDocClient;

final class ClientHardeningTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'api_url' => 'https://storage.example.test/api',
            'access_key_id' => 'access-id',
            'secret_access_key' => 'secret-key',
            'bucket' => 'bucket-id',
            'auth_mode' => 'basic',
            'max_retries' => 0,
        ], $overrides);
    }

    private function client(HandlerStack $handler, array $overrides = []): StoreDocClient
    {
        return new TestableStoreDocClient($this->config($overrides), $handler);
    }

    /**
     * @param  class-string  $class
     */
    private function invoke(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }

    #[Test]
    public function plain_http_requires_an_explicit_opt_in(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StoreDocClient($this->config(['api_url' => 'http://storage.example.test/api']));
    }

    #[Test]
    public function the_api_url_must_not_contain_credentials_or_a_query_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StoreDocClient($this->config(['api_url' => 'https://user:secret@storage.example.test/api']));
    }

    #[Test]
    public function the_api_url_must_not_contain_a_query_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StoreDocClient($this->config(['api_url' => 'https://storage.example.test/api?token=abc']));
    }

    #[Test]
    public function credentials_with_control_characters_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StoreDocClient($this->config(['secret_access_key' => "secret\nkey"]));
    }

    #[Test]
    public function paths_with_traversal_segments_are_rejected(): void
    {
        $client = new StoreDocClient($this->config());

        $this->expectException(InvalidArgumentException::class);

        $this->invoke($client, 'normalizePath', ['folder/../secret.txt']);
    }

    #[Test]
    public function paths_with_backslashes_are_rejected(): void
    {
        $client = new StoreDocClient($this->config());

        $this->expectException(InvalidArgumentException::class);

        $this->invoke($client, 'normalizePath', ['folder\\secret.txt']);
    }

    #[Test]
    public function paths_with_control_characters_are_rejected(): void
    {
        $client = new StoreDocClient($this->config());

        $this->expectException(InvalidArgumentException::class);

        $this->invoke($client, 'normalizePath', ["folder/secret\u{0000}.txt"]);
    }

    #[Test]
    public function a_rate_limit_is_retried_for_every_method(): void
    {
        $client = new StoreDocClient($this->config(['max_retries' => 2]));
        $decider = $this->invoke($client, 'retryDecider');

        $request = new Request('POST', 'https://storage.example.test/api/storage/upload');
        $response = new Response(429);

        $this->assertTrue($decider(0, $request, $response, null));
    }

    #[Test]
    public function a_gateway_error_is_not_retried_for_a_non_idempotent_method(): void
    {
        $client = new StoreDocClient($this->config(['max_retries' => 2]));
        $decider = $this->invoke($client, 'retryDecider');

        $request = new Request('POST', 'https://storage.example.test/api/storage/upload');
        $response = new Response(503);

        $this->assertFalse($decider(0, $request, $response, null));
    }

    #[Test]
    public function a_gateway_error_is_retried_for_an_idempotent_method(): void
    {
        $client = new StoreDocClient($this->config(['max_retries' => 2]));
        $decider = $this->invoke($client, 'retryDecider');

        $request = new Request('GET', 'https://storage.example.test/api/storage/objects');
        $response = new Response(502);

        $this->assertTrue($decider(0, $request, $response, null));
    }

    #[Test]
    public function the_retry_budget_is_respected(): void
    {
        $client = new StoreDocClient($this->config(['max_retries' => 2]));
        $decider = $this->invoke($client, 'retryDecider');

        $request = new Request('GET', 'https://storage.example.test/api/storage/objects');
        $response = new Response(429);

        $this->assertFalse($decider(2, $request, $response, null));
    }

    #[Test]
    public function a_shallow_listing_drops_objects_that_are_not_direct_children(): void
    {
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => '1', 'key' => 'docs/root.txt'],
                    ['id' => '2', 'key' => 'docs/sub/child.txt'],
                ],
                'meta' => ['last_page' => 1],
            ])),
        ]));

        $objects = $this->client($handler)->listObjects('docs/', false);

        $this->assertCount(1, $objects);
        $this->assertSame('docs/root.txt', $objects[0]['key']);
    }

    #[Test]
    public function a_deep_listing_keeps_every_descendant(): void
    {
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => '1', 'key' => 'docs/root.txt'],
                    ['id' => '2', 'key' => 'docs/sub/child.txt'],
                ],
                'meta' => ['last_page' => 1],
            ])),
        ]));

        $objects = $this->client($handler)->listObjects('docs/', true);

        $this->assertCount(2, $objects);
    }

    #[Test]
    public function a_presigned_url_is_rejected_when_tls_verification_is_enabled(): void
    {
        $client = new StoreDocClient($this->config());

        $this->expectException(RuntimeException::class);

        $client->validatePresignedUrl('http://cdn.example.test/file?token=abc');
    }

    #[Test]
    public function a_presigned_url_with_an_unknown_scheme_is_rejected(): void
    {
        $client = new StoreDocClient($this->config());

        $this->expectException(RuntimeException::class);

        $client->validatePresignedUrl('javascript:alert(1)');
    }

    #[Test]
    public function the_hmac_canonical_string_matches_the_server_format(): void
    {
        $client = new StoreDocClient($this->config(['auth_mode' => 'hmac']));

        $signature = $this->invoke($client, 'generateHmacSignature', ['GET', 'storage/objects', '1700000000', 'abc']);

        $expected = hash_hmac('sha256', "GET\nstorage/objects\n1700000000\nabc", 'secret-key');

        $this->assertSame($expected, $signature);
    }
}
