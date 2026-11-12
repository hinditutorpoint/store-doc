<?php

namespace StoreDoc\Laravel\Storage\Tests;

use StoreDoc\Laravel\Storage\StoreDocAdapter;
use StoreDoc\Laravel\Storage\StoreDocClient;

/**
 * Adapter that accepts a pre-built client double.
 */
class FakeAdapter extends StoreDocAdapter
{
    public function __construct(array $config, StoreDocClient $client)
    {
        parent::__construct($config);

        $this->client = $client;
    }
}
