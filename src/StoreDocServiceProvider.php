<?php

namespace StoreDoc\Laravel\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class StoreDocServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/store-doc.php', 'store-doc'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/config/store-doc.php' => config_path('store-doc.php'),
        ], 'store-doc-config');

        // Register custom filesystem driver
        Storage::extend('store-doc', function ($app, $config) {
            $adapter = new StoreDocAdapter($config);
            $filesystem = new Filesystem($adapter, $config);

            return new StoreDocFilesystem($filesystem, $adapter, $config);
        });
    }
}
