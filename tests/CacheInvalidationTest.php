<?php

namespace StoreDoc\Laravel\Storage\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StoreDoc\Laravel\Storage\StoreDocCache;

final class CacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        StoreDocCache::flushMemory();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(int $defaultTtl = 600): array
    {
        return [
            'cache' => [
                'enabled' => true,
                'store' => 'array',
                'prefix' => 'storedoc-test',
                'object_ttl' => 3600,
                'url_safety_margin' => 300,
                'url_default_ttl' => $defaultTtl,
            ],
        ];
    }

    #[Test]
    public function a_signed_url_stays_cached_until_the_path_is_forgotten(): void
    {
        $cache = new StoreDocCache($this->config());
        $url = 'https://cdn.example.test/file?token=abc';

        $cache->putUrl('bucket', 'file.txt', 'temporary', $url, ['exp' => 123]);

        $this->assertSame($url, $cache->getUrl('bucket', 'file.txt', 'temporary', ['exp' => 123]));
    }

    #[Test]
    public function forgetting_a_path_invalidates_every_cached_url_variant(): void
    {
        $cache = new StoreDocCache($this->config());
        $url = 'https://cdn.example.test/file?token=abc';

        $cache->putUrl('bucket', 'file.txt', 'public', $url);
        $cache->putUrl('bucket', 'file.txt', 'temporary', $url, ['exp' => 123]);
        $cache->putUrl('bucket', 'file.txt', 'download', $url, ['filename' => 'file.txt']);
        $cache->putObject('bucket', 'file.txt', ['id' => 'object-id']);

        $this->assertNotNull($cache->getUrl('bucket', 'file.txt', 'public'));
        $this->assertNotNull($cache->getUrl('bucket', 'file.txt', 'temporary', ['exp' => 123]));
        $this->assertNotNull($cache->getUrl('bucket', 'file.txt', 'download', ['filename' => 'file.txt']));
        $this->assertNotNull($cache->getObject('bucket', 'file.txt'));

        $cache->forgetPath('bucket', 'file.txt');

        $this->assertNull($cache->getUrl('bucket', 'file.txt', 'public'));
        $this->assertNull($cache->getUrl('bucket', 'file.txt', 'temporary', ['exp' => 123]));
        $this->assertNull($cache->getUrl('bucket', 'file.txt', 'download', ['filename' => 'file.txt']));
        $this->assertNull($cache->getObject('bucket', 'file.txt'));
    }

    #[Test]
    public function an_overwritten_file_does_not_keep_serving_the_previous_signed_url(): void
    {
        $cache = new StoreDocCache($this->config());
        $first = 'https://cdn.example.test/file?token=first';
        $second = 'https://cdn.example.test/file?token=second';

        $cache->putUrl('bucket', 'file.txt', 'temporary', $first, ['exp' => 123]);
        $cache->forgetPath('bucket', 'file.txt');
        $cache->putUrl('bucket', 'file.txt', 'temporary', $second, ['exp' => 123]);

        $this->assertSame($second, $cache->getUrl('bucket', 'file.txt', 'temporary', ['exp' => 123]));
    }

    #[Test]
    public function an_expired_signed_url_is_never_served(): void
    {
        $cache = new StoreDocCache($this->config());
        $url = 'https://cdn.example.test/file?expires='.(time() - 60);

        $cache->putUrl('bucket', 'file.txt', 'temporary', $url);

        $this->assertNull($cache->getUrl('bucket', 'file.txt', 'temporary'));
        $this->assertTrue($cache->isUrlExpired($url));
    }

    #[Test]
    public function the_expiry_of_an_s3_style_url_is_understood(): void
    {
        $cache = new StoreDocCache($this->config());
        $date = gmdate('Ymd\THis\Z', time());
        $url = 'https://cdn.example.test/file?X-Amz-Date='.$date.'&X-Amz-Expires=600&X-Amz-Signature=abc';

        $this->assertFalse($cache->isUrlExpired($url));
        $this->assertSame(300, $cache->calculateUrlTtl($url));
    }

    #[Test]
    public function an_unknown_expiry_falls_back_to_the_configured_default(): void
    {
        $cache = new StoreDocCache($this->config(900));
        $url = 'https://cdn.example.test/file?token=abc';

        $this->assertSame(900, $cache->calculateUrlTtl($url));
        $this->assertFalse($cache->isUrlExpired($url));
    }

    #[Test]
    public function the_in_memory_fallback_cache_stays_bounded(): void
    {
        $cache = new StoreDocCache($this->config());

        for ($i = 0; $i < 1200; $i++) {
            $cache->putObject('bucket', 'file-'.$i.'.txt', ['id' => (string) $i]);
        }

        $property = new \ReflectionProperty(StoreDocCache::class, 'memoryCache');
        $property->setAccessible(true);

        $this->assertLessThanOrEqual(1000, count($property->getValue()));
    }
}
