<?php

namespace StoreDoc\Laravel\Storage\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use StoreDoc\Laravel\Storage\StoreDocClient;

/**
 * Client that accepts an injected Guzzle handler stack.
 */
class TestableStoreDocClient extends StoreDocClient
{
    public function __construct(array $config, private HandlerStack $handler)
    {
        parent::__construct($config);
    }

    protected function createHttpClient(array $options): Client
    {
        $options['handler'] = $this->handler;

        return parent::createHttpClient($options);
    }
}
