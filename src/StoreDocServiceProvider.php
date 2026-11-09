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

        // Register previewUrl macro so any disk in the project can use previewUrl
        if (! FilesystemAdapter::hasMacro('previewUrl')) {
            FilesystemAdapter::macro('previewUrl', function ($path, $expiration = null, $filename = null) {
                if (method_exists($this, 'previewUrl')) {
                    return $this->previewUrl($path, $expiration, $filename);
                }

                return $this->url($path);
            });
        }

        // Register downloadUrl macro so any disk in the project can use downloadUrl
        if (! FilesystemAdapter::hasMacro('downloadUrl')) {
            FilesystemAdapter::macro('downloadUrl', function ($path, $expiration = null, $filename = null) {
                if (method_exists($this, 'downloadUrl')) {
                    return $this->downloadUrl($path, $expiration, $filename);
                }

                return $this->url($path);
            });
        }
    }
}
