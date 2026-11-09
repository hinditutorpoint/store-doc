# Store-Doc Laravel Storage Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/store-doc/laravel-storage-driver.svg?style=flat-square)](https://packagist.org/packages/store-doc/laravel-storage-driver)
[![Total Downloads](https://img.shields.io/packagist/dt/store-doc/laravel-storage-driver.svg?style=flat-square)](https://packagist.org/packages/store-doc/laravel-storage-driver)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%5E8.3-blue.svg?style=flat-square)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/Laravel-11.x%20%7C%2012.x%20%7C%2013.x-red.svg?style=flat-square)](https://laravel.com/)

A Laravel package that provides a custom filesystem driver for Store-Doc S3-compatible storage service. Use Laravel's Storage facade to interact with your Store-Doc storage seamlessly.

## Features

- ✅ Full Laravel Storage facade support
- ✅ All standard filesystem operations (upload, download, delete, list, etc.)
- ✅ API key authentication
- ✅ Automatic service provider registration
- ✅ Easy configuration
- ✅ Compatible with Laravel 11, 12, and 13

## Installation

### 1. Install via Composer

```bash
composer require store-doc/laravel-storage-driver
```

### 2. Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag=store-doc-config
```

### 3. Configure Environment Variables

Add the following to your `.env` file:

```env
STORE_DOC_API_URL=https://your-domain.com/api
STORE_DOC_ACCESS_KEY_ID=your_access_key_id
STORE_DOC_SECRET_KEY=your_secret_access_key
STORE_DOC_BUCKET=your_bucket_id
# Local HTTP development only. Keep false in production.
STORE_DOC_ALLOW_INSECURE_HTTP=false
```

### 4. Add Disk Configuration

In `config/filesystems.php`, add a new disk:

```php
'disks' => [
    // ... other disks

    's3-store-doc' => [
        'driver' => 'store-doc',
        'api_url' => env('STORE_DOC_API_URL'),
        'access_key_id' => env('STORE_DOC_ACCESS_KEY_ID'),
        'secret_access_key' => env('STORE_DOC_SECRET_KEY'),
        'bucket' => env('STORE_DOC_BUCKET'),
        'timeout' => env('STORE_DOC_TIMEOUT', 30),
        'connect_timeout' => env('STORE_DOC_CONNECT_TIMEOUT', 10),
        'verify_ssl' => env('STORE_DOC_VERIFY_SSL', true),
        'allow_insecure_http' => env('STORE_DOC_ALLOW_INSECURE_HTTP', false),
        'allow_insecure_tls' => env('STORE_DOC_ALLOW_INSECURE_TLS', false),
        'max_temporary_url_minutes' => env('STORE_DOC_MAX_TEMPORARY_URL_MINUTES', 1440),
        'max_listing_pages' => env('STORE_DOC_MAX_LISTING_PAGES', 1000),
    ],
],
```

`api_url` may be either the application root (for example `https://storage.example.com`)
or its API root (for example `https://storage.example.com/api`); the driver normalizes
both to the Store-Doc API root. HTTPS and certificate verification are required by
default. For local HTTP development only, explicitly enable
`allow_insecure_http`; never disable TLS verification in production.

Temporary URL lifetimes are passed to Store-Doc in minutes and are limited to
`max_temporary_url_minutes` (1440 by default). Keep URLs short-lived and do not log
or persist their signed query strings.
Object, bucket, and folder listings follow pagination and stop at
`max_listing_pages` (1000 by default) to bound unexpected response sizes.

## Usage

### Basic File Operations

#### Upload Files

```php
use Illuminate\Support\Facades\Storage;

// Upload from string
Storage::disk('s3-store-doc')->put('documents/report.pdf', $pdfContents);

// Upload from file
Storage::disk('s3-store-doc')->putFile('images', $request->file('photo'));

// Upload with visibility
Storage::disk('s3-store-doc')->put('public/avatar.jpg', $contents, 'public');
```

#### Download Files

```php
// Get file contents
$contents = Storage::disk('s3-store-doc')->get('documents/report.pdf');

// Download file
return Storage::disk('s3-store-doc')->download('documents/report.pdf');

// Get file as stream
$stream = Storage::disk('s3-store-doc')->readStream('large-file.zip');
```

### Delete Files

```php
// Delete single file
Storage::disk('s3-store-doc')->delete('documents/old-report.pdf');

// Delete multiple files
Storage::disk('s3-store-doc')->delete([
    'file1.jpg',
    'file2.jpg',
]);

// Delete directory
Storage::disk('s3-store-doc')->deleteDirectory('old-folder');
```

### List Files

```php
// List files in directory
$files = Storage::disk('s3-store-doc')->files('documents');

// List all files recursively
$allFiles = Storage::disk('s3-store-doc')->allFiles('documents');

// List directories
$directories = Storage::disk('s3-store-doc')->directories('uploads');
```

### Check File Existence

```php
if (Storage::disk('s3-store-doc')->exists('documents/report.pdf')) {
    // File exists
}

if (Storage::disk('s3-store-doc')->missing('documents/report.pdf')) {
    // File doesn't exist
}
```

### Get File Metadata

```php
// File size (in bytes)
$size = Storage::disk('s3-store-doc')->size('documents/report.pdf');

// Last modified timestamp
$timestamp = Storage::disk('s3-store-doc')->lastModified('documents/report.pdf');

// MIME type
$mimeType = Storage::disk('s3-store-doc')->mimeType('image.jpg');
```

### Get File URLs

```php
// Get presigned URL
$url = Storage::disk('s3-store-doc')->url('documents/report.pdf');

// Use in views
<img src="{{ Storage::disk('s3-store-doc')->url('images/photo.jpg') }}" />
```

### Copy and Move Files

```php
// Copy file
Storage::disk('s3-store-doc')->copy('old-path.jpg', 'new-path.jpg');

// Move file
Storage::disk('s3-store-doc')->move('old-path.jpg', 'new-path.jpg');
```

### Download Files

```php
// Force download with custom filename
return Storage::disk('s3-store-doc')->download('documents/report.pdf', 'monthly-report.pdf');

// Download with custom headers
return Storage::disk('s3-store-doc')->download('file.pdf', 'download.pdf', [
    'Cache-Control' => 'no-cache',
]);

// Get download URL (uses smart cache, default 7 days)
$downloadUrl = Storage::disk('s3-store-doc')->downloadUrl('documents/report.pdf', null, 'report.pdf');

// Temporary download URL (expires in 2 hours with custom filename)
$downloadUrl = Storage::disk('s3-store-doc')->downloadUrl('documents/report.pdf', now()->addHours(2), 'monthly-report.pdf');
```

### Preview Files (Inline Display)

```php
// Display image in browser
return Storage::disk('s3-store-doc')->response('images/photo.jpg');

// Display PDF in browser
return Storage::disk('s3-store-doc')->response('documents/report.pdf');

// Get preview URL (inline display with smart cache, default 7 days)
$previewUrl = Storage::disk('s3-store-doc')->previewUrl('images/photo.jpg');

// Temporary preview URL (expires in 1 hour)
$previewUrl = Storage::disk('s3-store-doc')->previewUrl('documents/report.pdf', now()->addHour());
```

### Built-in Smart Caching

The package includes automatic zero-configuration caching for object metadata and signed URLs.
- **Zero HTTP latency on hits:** Subsequent calls to `url()`, `previewUrl()`, and `downloadUrl()` resolve instantly from cache.
- **Smart TTL:** Automatically calculates URL expiration and safely refreshes 5 minutes before the signature expires.
- **Auto-invalidation:** Deleting, updating, moving, or writing a file immediately flushes its cached metadata and URLs.

Configure caching in `config/store-doc.php`:
```php
'cache' => [
    'enabled' => env('STORE_DOC_CACHE_ENABLED', true),
    'store' => env('STORE_DOC_CACHE_STORE', null), // null uses default Laravel cache store
    'prefix' => env('STORE_DOC_CACHE_PREFIX', 'storedoc'),
    'object_ttl' => env('STORE_DOC_OBJECT_CACHE_TTL', 3600), // 1 hour for metadata
    'url_safety_margin' => env('STORE_DOC_URL_SAFETY_MARGIN', 300), // 5 min safety buffer
],
```

### Temporary URLs

```php
// Generate URL that expires in 5 minutes
$url = Storage::disk('s3-store-doc')->temporaryUrl(
    'private/document.pdf',
    now()->addMinutes(5)
);

// Temporary URL with 1 hour expiration
$url = Storage::disk('s3-store-doc')->temporaryUrl(
    'secure/file.zip',
    now()->addHour()
);

// Use in email or temporary share link
Mail::send('emails.share', ['url' => $url], function ($message) {
    // Send email with temporary link
});
```

### File Checksums

```php
// Get MD5 checksum
$md5 = Storage::disk('s3-store-doc')->checksum('file.zip');

// Get SHA256 checksum
$sha256 = Storage::disk('s3-store-doc')->checksum('file.zip', [
    'checksum_algorithm' => 'sha256'
]);

// Verify file integrity
$expectedHash = '5d41402abc4b2a76b9719d911017c592';
$actualHash = Storage::disk('s3-store-doc')->checksum('file.txt');
if ($expectedHash === $actualHash) {
    // File is intact
}
```

### JSON Files

```php
// Read JSON file
$config = Storage::disk('s3-store-doc')->json('config/settings.json');

// Write JSON file
Storage::disk('s3-store-doc')->put('config/settings.json', json_encode($data));
```

### Append and Prepend

```php
// Append to file
Storage::disk('s3-store-doc')->append('logs/app.log', "New log entry\n");

// Prepend to file
Storage::disk('s3-store-doc')->prepend('file.txt', "Header line\n");
```

### Enhanced Directory Operations

```php
// List only files (non-recursive)
$files = Storage::disk('s3-store-doc')->files('documents');

// List all files recursively
$allFiles = Storage::disk('s3-store-doc')->allFiles('documents');

// List only directories
$directories = Storage::disk('s3-store-doc')->directories('uploads');

// List all directories recursively
$allDirectories = Storage::disk('s3-store-doc')->allDirectories('uploads');

// Create directory
Storage::disk('s3-store-doc')->makeDirectory('uploads/2024');

// Check if file is missing
if (Storage::disk('s3-store-doc')->missing('file.pdf')) {
    // File doesn't exist
}
```

### Bucket and Folder Management

The package also provides methods to create and manage buckets and folders programmatically.

#### Create a Bucket

```php
use StoreDoc\Laravel\Storage\StoreDocClient;

$client = new StoreDocClient(config('filesystems.disks.s3-store-doc'));

$bucket = $client->createBucket([
    'name' => 'my-app-uploads',
    'description' => 'Application file uploads',
    'visibility' => 'private',
    'versioning_enabled' => true,
    'encryption_enabled' => true,
]);
```

#### Create a Folder

```php
$folder = $client->createFolder([
    'bucket_id' => 'your-bucket-uuid',
    'name' => 'Documents',
    'visibility' => 'private',
]);

// Create nested folder
$subfolder = $client->createFolder([
    'bucket_id' => 'your-bucket-uuid',
    'parent_id' => $folder['id'],
    'name' => '2024',
]);
```

**For complete bucket and folder management documentation, see [BUCKET_FOLDER_GUIDE.md](BUCKET_FOLDER_GUIDE.md)**

## Advanced Usage

### Set Default Disk

In `config/filesystems.php`:

```php
'default' => 's3-store-doc',
```

Then use Storage facade without specifying disk:

```php
Storage::put('file.txt', 'contents'); // Uses s3-store-doc
```

### Use in Models

```php
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    public function uploadFile($file)
    {
        $path = Storage::disk('s3-store-doc')->putFile('documents', $file);
        $this->update(['file_path' => $path]);
    }

    public function getFileUrl()
    {
        return Storage::disk('s3-store-doc')->url($this->file_path);
    }
}
```

## Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `api_url` | Store-Doc API base URL | - |
| `access_key_id` | Your API access key ID | - |
| `secret_access_key` | Your API secret key | - |
| `bucket` | Bucket ID to use | - |
| `timeout` | HTTP request timeout (seconds) | 30 |
| `verify_ssl` | Verify SSL certificates | true |

## Getting API Credentials

1. Log in to your Store-Doc account
2. Navigate to **API Keys** section
3. Click **Create New API Key**
4. Copy the **Access Key ID** and **Secret Access Key**
5. Get your **Bucket ID** from the Buckets page

## Requirements

- PHP 8.3 or higher
- Laravel 11.x, 12.x, or 13.x
- Store-Doc account with API access

## Troubleshooting

### Authentication Failed

Ensure your API credentials are correct:
- Check `STORE_DOC_ACCESS_KEY_ID` and `STORE_DOC_SECRET_KEY` in `.env`
- Verify the API key is active in your Store-Doc dashboard

### File Not Found

- Verify the file path is correct
- Check bucket permissions
- Ensure the bucket ID is correct

### Connection Timeout

- Increase timeout in config: `'timeout' => 60`
- Check your network connection
- Verify API URL is accessible

## License

MIT License

## Support & Contributing

- **GitHub Repository**: [https://github.com/hinditutorpoint/store-doc](https://github.com/hinditutorpoint/store-doc)
- **Issues & Bug Reports**: [https://github.com/hinditutorpoint/store-doc/issues](https://github.com/hinditutorpoint/store-doc/issues)
- **Packagist Package**: [https://packagist.org/packages/store-doc/laravel-storage-driver](https://packagist.org/packages/store-doc/laravel-storage-driver)
- **Documentation**: [https://github.com/hinditutorpoint/store-doc#readme](https://github.com/hinditutorpoint/store-doc#readme)
