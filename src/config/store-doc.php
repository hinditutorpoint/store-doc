<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Store-Doc API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Store-Doc storage service API credentials and settings.
    |
    */

    'api_url' => env('STORE_DOC_API_URL', 'https://your-domain.com/api'),

    'access_key_id' => env('STORE_DOC_ACCESS_KEY_ID'),

    'secret_access_key' => env('STORE_DOC_SECRET_KEY'),

    'bucket' => env('STORE_DOC_BUCKET'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Mode  [S-01]
    |--------------------------------------------------------------------------
    |
    | "hmac"  – HMAC-SHA256 request signing.  The secret key never leaves the
    |           client; only the access-key ID, a timestamp, and a signature
    |           are sent in the Authorization header.  **Recommended.**
    |           Requires server-side HMAC verification support.
    |
    | "basic" – Legacy mode.  The secret key is concatenated with the access
    |           key ID and sent in an X-Api-Key header on every request.
    |           Use only when the server does not yet support HMAC.
    |
    */

    'auth_mode' => env('STORE_DOC_AUTH_MODE', 'hmac'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration
    |--------------------------------------------------------------------------
    */

    'timeout' => env('STORE_DOC_TIMEOUT', 30),

    'connect_timeout' => env('STORE_DOC_CONNECT_TIMEOUT', 10),

    'verify_ssl' => env('STORE_DOC_VERIFY_SSL', true),

    'allow_insecure_http' => env('STORE_DOC_ALLOW_INSECURE_HTTP', false),

    'allow_insecure_tls' => env('STORE_DOC_ALLOW_INSECURE_TLS', false),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration  [S-03]
    |--------------------------------------------------------------------------
    |
    | max_retries: Number of automatic retries on transient failures
    |              (429, 502, 503, 504, and connection errors).
    |              Uses exponential back-off and respects Retry-After headers.
    |              Set to 0 to disable retries entirely.
    |
    */

    'max_retries' => env('STORE_DOC_MAX_RETRIES', 3),

    'temporary_url_minutes' => env('STORE_DOC_TEMPORARY_URL_MINUTES', 60),

    'max_temporary_url_minutes' => env('STORE_DOC_MAX_TEMPORARY_URL_MINUTES', 1440),

    'max_listing_pages' => env('STORE_DOC_MAX_LISTING_PAGES', 1000),

    /*
    |--------------------------------------------------------------------------
    | Package Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Automatically caches object metadata and signed URLs to eliminate
    | repeated HTTP requests. Smart TTL refreshes URLs before expiration.
    |
    */
    'cache' => [
        'enabled' => env('STORE_DOC_CACHE_ENABLED', true),
        'store' => env('STORE_DOC_CACHE_STORE', null), // null uses default Laravel cache store
        'prefix' => env('STORE_DOC_CACHE_PREFIX', 'storedoc'),
        'object_ttl' => env('STORE_DOC_OBJECT_CACHE_TTL', 3600), // 1 hour for metadata
        'url_safety_margin' => env('STORE_DOC_URL_SAFETY_MARGIN', 300), // 5 minutes before signature expires
        'url_default_ttl' => env('STORE_DOC_URL_CACHE_TTL', 3600), // fallback TTL when the expiry cannot be read
    ],

];
