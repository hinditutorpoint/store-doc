# Example Laravel Application Using Store-Doc Storage

This example demonstrates how to use the Store-Doc Laravel Storage Driver in a real application.

## Setup

### 1. Install Package

```bash
composer require store-doc/laravel-storage-driver
```

### 2. Configure `.env`

```env
STORE_DOC_API_URL=https://api.store-doc.com/api
STORE_DOC_ACCESS_KEY_ID=your_access_key_id
STORE_DOC_SECRET_KEY=your_secret_access_key
STORE_DOC_BUCKET=bucket-uuid-here
```

### 3. Add Disk in `config/filesystems.php`

```php
'disks' => [
    's3-store-doc' => [
        'driver' => 'store-doc',
        'api_url' => env('STORE_DOC_API_URL'),
        'access_key_id' => env('STORE_DOC_ACCESS_KEY_ID'),
        'secret_access_key' => env('STORE_DOC_SECRET_KEY'),
        'bucket' => env('STORE_DOC_BUCKET'),
    ],
],
```

## Example Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    /**
     * Upload a file
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
        ]);

        $file = $request->file('file');
        
        // Upload to Store-Doc
        $path = Storage::disk('s3-store-doc')->putFile('uploads', $file);

        return response()->json([
            'success' => true,
            'path' => $path,
            'url' => Storage::disk('s3-store-doc')->url($path),
        ]);
    }

    /**
     * Download a file
     */
    public function download($path)
    {
        if (!Storage::disk('s3-store-doc')->exists($path)) {
            abort(404, 'File not found');
        }

        return Storage::disk('s3-store-doc')->download($path);
    }

    /**
     * List all files
     */
    public function list()
    {
        $files = Storage::disk('s3-store-doc')->allFiles('uploads');

        $fileData = collect($files)->map(function ($file) {
            return [
                'path' => $file,
                'size' => Storage::disk('s3-store-doc')->size($file),
                'modified' => Storage::disk('s3-store-doc')->lastModified($file),
                'url' => Storage::disk('s3-store-doc')->url($file),
            ];
        });

        return response()->json($fileData);
    }

    /**
     * Delete a file
     */
    public function delete($path)
    {
        if (Storage::disk('s3-store-doc')->exists($path)) {
            Storage::disk('s3-store-doc')->delete($path);
            
            return response()->json([
                'success' => true,
                'message' => 'File deleted successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'File not found',
        ], 404);
    }
}
```

## Example Routes

```php
// routes/web.php or routes/api.php

use App\Http\Controllers\FileController;

Route::post('/files/upload', [FileController::class, 'upload']);
Route::get('/files/download/{path}', [FileController::class, 'download'])->where('path', '.*');
Route::get('/files', [FileController::class, 'list']);
Route::delete('/files/{path}', [FileController::class, 'delete'])->where('path', '.*');
```

## Example Model with File Upload

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    protected $fillable = ['title', 'file_path', 'file_size', 'mime_type'];

    /**
     * Upload and attach file to document
     */
    public function uploadFile($file)
    {
        // Delete old file if exists
        if ($this->file_path) {
            Storage::disk('s3-store-doc')->delete($this->file_path);
        }

        // Upload new file
        $path = Storage::disk('s3-store-doc')->putFile('documents', $file);

        // Update model
        $this->update([
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        return $this;
    }

    /**
     * Get file URL
     */
    public function getFileUrlAttribute()
    {
        return $this->file_path 
            ? Storage::disk('s3-store-doc')->url($this->file_path)
            : null;
    }

    /**
     * Delete file when model is deleted
     */
    protected static function booted()
    {
        static::deleting(function ($document) {
            if ($document->file_path) {
                Storage::disk('s3-store-doc')->delete($document->file_path);
            }
        });
    }
}
```

## Example Blade View

```blade
<!-- resources/views/upload.blade.php -->

<!DOCTYPE html>
<html>
<head>
    <title>File Upload</title>
</head>
<body>
    <h1>Upload File to Store-Doc</h1>

    <form action="/files/upload" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="file" name="file" required>
        <button type="submit">Upload</button>
    </form>

    @if(session('success'))
        <p>File uploaded: <a href="{{ session('url') }}">{{ session('path') }}</a></p>
    @endif

    <h2>Uploaded Files</h2>
    <ul>
        @foreach($files ?? [] as $file)
            <li>
                <a href="{{ $file['url'] }}">{{ $file['path'] }}</a>
                ({{ number_format($file['size'] / 1024, 2) }} KB)
            </li>
        @endforeach
    </ul>
</body>
</html>
```

## Advanced Examples

### Image Preview and Download

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    /**
     * Display image inline (preview in browser)
     */
    public function preview($path)
    {
        if (!Storage::disk('s3-store-doc')->exists($path)) {
            abort(404);
        }

        // Returns image with Content-Disposition: inline
        return Storage::disk('s3-store-doc')->response($path);
    }

    /**
     * Force download image
     */
    public function download($path)
    {
        if (!Storage::disk('s3-store-doc')->exists($path)) {
            abort(404);
        }

        $filename = 'photo-' . date('Y-m-d') . '.jpg';
        
        // Returns image with Content-Disposition: attachment
        return Storage::disk('s3-store-doc')->download($path, $filename);
    }

    /**
     * Get shareable URLs
     */
    public function getUrls($path)
    {
        return response()->json([
            'preview_url' => Storage::disk('s3-store-doc')->previewUrl($path),
            'download_url' => Storage::disk('s3-store-doc')->downloadUrl($path, 'image.jpg'),
            'permanent_url' => Storage::disk('s3-store-doc')->url($path),
        ]);
    }
}
```

### Temporary Shared Links

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ShareController extends Controller
{
    /**
     * Generate temporary share link
     */
    public function createShareLink(Request $request)
    {
        $path = $request->input('file_path');
        $duration = $request->input('duration', 60); // minutes

        // Generate URL that expires after specified duration
        $url = Storage::disk('s3-store-doc')->temporaryUrl(
            $path,
            now()->addMinutes($duration)
        );

        return response()->json([
            'share_url' => $url,
            'expires_at' => now()->addMinutes($duration)->toIso8601String(),
        ]);
    }

    /**
     * Send file via email with temporary link
     */
    public function emailFile(Request $request)
    {
        $path = $request->input('file_path');
        $email = $request->input('email');

        // 24-hour temporary link
        $url = Storage::disk('s3-store-doc')->temporaryUrl(
            $path,
            now()->addDay()
        );

        Mail::to($email)->send(new FileShareMail($url, $path));

        return response()->json(['success' => true]);
    }
}
```

### PDF Viewer

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    /**
     * Display PDF in browser
     */
    public function viewPdf($path)
    {
        if (!Storage::disk('s3-store-doc')->exists($path)) {
            abort(404);
        }

        // Display PDF inline in browser
        return Storage::disk('s3-store-doc')->response($path, null, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Serve PDF viewer page
     */
    public function pdfViewer($path)
    {
        $previewUrl = Storage::disk('s3-store-doc')->previewUrl($path);
        
        return view('pdf-viewer', [
            'pdf_url' => $previewUrl,
            'filename' => basename($path),
        ]);
    }
}
```

**PDF Viewer Blade Template (resources/views/pdf-viewer.blade.php):**

```blade
<!DOCTYPE html>
<html>
<head>
    <title>PDF Viewer - {{ $filename }}</title>
    <style>
        body, html { margin: 0; padding: 0; height: 100%; }
        iframe { width: 100%; height: 100%; border: none; }
    </style>
</head>
<body>
    <iframe src="{{ $pdf_url }}" type="application/pdf"></iframe>
</body>
</html>
```

### File Integrity Verification

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class FileVerificationService
{
    /**
     * Verify file integrity using checksum
     */
    public function verifyFile($path, $expectedHash, $algorithm = 'md5')
    {
        if (!Storage::disk('s3-store-doc')->exists($path)) {
            return ['valid' => false, 'error' => 'File not found'];
        }

        $actualHash = Storage::disk('s3-store-doc')->checksum($path, [
            'checksum_algorithm' => $algorithm
        ]);

        return [
            'valid' => $actualHash === $expectedHash,
            'expected' => $expectedHash,
            'actual' => $actualHash,
            'algorithm' => $algorithm,
        ];
    }

    /**
     * Generate and store checksums for uploaded files
     */
    public function storeWithChecksum($file, $directory)
    {
        // Upload file
        $path = Storage::disk('s3-store-doc')->putFile($directory, $file);

        // Generate checksums
        $md5 = Storage::disk('s3-store-doc')->checksum($path);
        $sha256 = Storage::disk('s3-store-doc')->checksum($path, [
            'checksum_algorithm' => 'sha256'
        ]);

        return [
            'path' => $path,
            'size' => Storage::disk('s3-store-doc')->size($path),
            'md5' => $md5,
            'sha256' => $sha256,
        ];
    }
}
```

### JSON Configuration Files

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class ConfigService
{
    /**
     * Load configuration from JSON file
     */
    public function loadConfig($path)
    {
        if (!Storage::disk('s3-store-doc')->exists($path)) {
            return [];
        }

        return Storage::disk('s3-store-doc')->json($path) ?? [];
    }

    /**
     * Save configuration to JSON file
     */
    public function saveConfig($path, array $data)
    {
        return Storage::disk('s3-store-doc')->put(
            $path,
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Update specific config value
     */
    public function updateConfig($path, $key, $value)
    {
        $config = $this->loadConfig($path);
        $config[$key] = $value;
        return $this->saveConfig($path, $config);
    }
}
```

### Log File Management

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class LogService
{
    protected $logPath = 'logs/app.log';

    /**
     * Append log entry
     */
    public function log($message, $level = 'INFO')
    {
        $entry = sprintf(
            "[%s] [%s] %s\n",
            now()->toIso8601String(),
            $level,
            $message
        );

        Storage::disk('s3-store-doc')->append($this->logPath, $entry);
    }

    /**
     * Read log file
     */
    public function getLogs()
    {
        if (!Storage::disk('s3-store-doc')->exists($this->logPath)) {
            return '';
        }

        return Storage::disk('s3-store-doc')->get($this->logPath);
    }

    /**
     * Clear log file
     */
    public function clearLogs()
    {
        if (Storage::disk('s3-store-doc')->exists($this->logPath)) {
            Storage::disk('s3-store-doc')->delete($this->logPath);
        }
    }
}
```

### Gallery Management

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class GalleryController extends Controller
{
    /**
     * List all images in gallery
     */
    public function index()
    {
        $images = Storage::disk('s3-store-doc')->allFiles('gallery/images');

        $gallery = collect($images)->map(function ($path) {
            return [
                'path' => $path,
                'preview_url' => Storage::disk('s3-store-doc')->previewUrl($path),
                'download_url' => Storage::disk('s3-store-doc')->downloadUrl($path),
                'thumbnail_url' => $this->getThumbnailUrl($path),
                'size' => Storage::disk('s3-store-doc')->size($path),
                'mime_type' => Storage::disk('s3-store-doc')->mimeType($path),
            ];
        });

        return view('gallery', ['images' => $gallery]);
    }

    /**
     * Get thumbnail URL (assumes thumbnails are in gallery/thumbnails)
     */
    protected function getThumbnailUrl($path)
    {
        $filename = basename($path);
        $thumbnailPath = 'gallery/thumbnails/' . $filename;

        if (Storage::disk('s3-store-doc')->exists($thumbnailPath)) {
            return Storage::disk('s3-store-doc')->previewUrl($thumbnailPath);
        }

        return Storage::disk('s3-store-doc')->previewUrl($path);
    }
}
```

### Batch File Operations

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class BatchFileService
{
    /**
     * Upload multiple files
     */
    public function uploadMultiple($files, $directory)
    {
        $results = [];

        foreach ($files as $file) {
            $path = Storage::disk('s3-store-doc')->putFile($directory, $file);
            
            $results[] = [
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'url' => Storage::disk('s3-store-doc')->url($path),
                'size' => $file->getSize(),
            ];
        }

        return $results;
    }

    /**
     * Copy directory to another location
     */
    public function copyDirectory($from, $to)
    {
        $files = Storage::disk('s3-store-doc')->allFiles($from);

        foreach ($files as $file) {
            $destination = str_replace($from, $to, $file);
            Storage::disk('s3-store-doc')->copy($file, $destination);
        }

        return count($files);
    }

    /**
     * Delete old files (older than specified days)
     */
    public function deleteOldFiles($directory, $days)
    {
        $files = Storage::disk('s3-store-doc')->allFiles($directory);
        $threshold = now()->subDays($days)->timestamp;
        $deleted = 0;

        foreach ($files as $file) {
            $lastModified = Storage::disk('s3-store-doc')->lastModified($file);
            
            if ($lastModified < $threshold) {
                Storage::disk('s3-store-doc')->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
```

## Testing

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FileUploadTest extends TestCase
{
    public function test_file_upload()
    {
        Storage::fake('s3-store-doc');

        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->post('/files/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        Storage::disk('s3-store-doc')->assertExists('uploads/' . $file->hashName());
    }
}
```
