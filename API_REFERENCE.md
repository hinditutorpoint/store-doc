# StoreDoc Laravel Storage Driver - API Reference

Complete reference for all available methods in the Store-Doc Laravel Storage Driver package.

## Table of Contents

- [File Operations](#file-operations)
- [Directory Operations](#directory-operations)
- [File Information](#file-information)
- [URL Generation](#url-generation)
- [Response Generation](#response-generation)
- [Advanced Operations](#advanced-operations)

---

## File Operations

### `put()`

Write contents to a file.

```php
Storage::disk('s3-store-doc')->put(string $path, string|resource $contents, mixed $options = []): bool
```

**Parameters:**
- `$path` - File path
- `$contents` - File contents (string or resource)
- `$options` - Optional array with `visibility` key

**Returns:** `bool` - True on success

**Example:**
```php
Storage::disk('s3-store-doc')->put('documents/report.pdf', $pdfContents);
Storage::disk('s3-store-doc')->put('public/image.jpg', $imageContents, 'public');
```

---

### `get()`

Get the contents of a file.

```php
Storage::disk('s3-store-doc')->get(string $path): string|null
```

**Parameters:**
- `$path` - File path

**Returns:** `string|null` - File contents or null if not found

**Example:**
```php
$contents = Storage::disk('s3-store-doc')->get('documents/report.pdf');
```

---

### `json()`

Get the contents of a JSON file as a decoded array.

```php
Storage::disk('s3-store-doc')->json(string $path, int $flags = 0): array|null
```

**Parameters:**
- `$path` - File path
- `$flags` - JSON decode flags

**Returns:** `array|null` - Decoded JSON data or null

**Example:**
```php
$config = Storage::disk('s3-store-doc')->json('config/settings.json');
```

---

### `readStream()`

Get a resource to read the file as a stream.

```php
Storage::disk('s3-store-doc')->readStream(string $path): resource|null
```

**Parameters:**
- `$path` - File path

**Returns:** `resource|null` - Stream resource or null

**Example:**
```php
$stream = Storage::disk('s3-store-doc')->readStream('large-file.zip');
fpassthru($stream);
fclose($stream);
```

---

### `writeStream()`

Write a new file using a stream.

```php
Storage::disk('s3-store-doc')->writeStream(string $path, resource $resource, array $options = []): bool
```

**Parameters:**
- `$path` - File path
- `$resource` - Stream resource
- `$options` - Optional configuration

**Returns:** `bool` - True on success

---

### `append()`

Append data to a file.

```php
Storage::disk('s3-store-doc')->append(string $path, string $data): bool
```

**Parameters:**
- `$path` - File path
- `$data` - Data to append

**Returns:** `bool` - True on success

**Example:**
```php
Storage::disk('s3-store-doc')->append('logs/app.log', "New log entry\n");
```

---

### `prepend()`

Prepend data to a file.

```php
Storage::disk('s3-store-doc')->prepend(string $path, string $data): bool
```

**Parameters:**
- `$path` - File path
- `$data` - Data to prepend

**Returns:** `bool` - True on success

**Example:**
```php
Storage::disk('s3-store-doc')->prepend('file.txt', "Header\n");
```

---

### `delete()`

Delete a file or array of files.

```php
Storage::disk('s3-store-doc')->delete(string|array $paths): bool
```

**Parameters:**
- `$paths` - File path(s) to delete

**Returns:** `bool` - True on success

**Example:**
```php
Storage::disk('s3-store-doc')->delete('old-file.txt');
Storage::disk('s3-store-doc')->delete(['file1.jpg', 'file2.jpg']);
```

---

### `copy()`

Copy a file to a new location.

```php
Storage::disk('s3-store-doc')->copy(string $from, string $to): bool
```

**Parameters:**
- `$from` - Source path
- `$to` - Destination path

**Returns:** `bool` - True on success

**Example:**
```php
Storage::disk('s3-store-doc')->copy('original.jpg', 'backup/original.jpg');
```

---

### `move()`

Move a file to a new location.

```php
Storage::disk('s3-store-doc')->move(string $from, string $to): bool
```

**Parameters:**
- `$from` - Source path
- `$to` - Destination path

**Returns:** `bool` - True on success

**Example:**
```php
Storage::disk('s3-store-doc')->move('temp/file.pdf', 'documents/file.pdf');
```

---

### `putFile()`

Store an uploaded file.

```php
Storage::disk('s3-store-doc')->putFile(string $path, File|UploadedFile|string $file, mixed $options = []): string|false
```

**Parameters:**
- `$path` - Directory path
- `$file` - File instance or path
- `$options` - Optional configuration

**Returns:** `string|false` - Stored file path or false

**Example:**
```php
$path = Storage::disk('s3-store-doc')->putFile('uploads', $request->file('photo'));
```

---

### `putFileAs()`

Store an uploaded file with a specific name.

```php
Storage::disk('s3-store-doc')->putFileAs(string $path, File|UploadedFile|string $file, string $name, mixed $options = []): string|false
```

**Parameters:**
- `$path` - Directory path
- `$file` - File instance or path
- `$name` - Desired filename
- `$options` - Optional configuration

**Returns:** `string|false` - Stored file path or false

**Example:**
```php
$path = Storage::disk('s3-store-doc')->putFileAs('uploads', $request->file('photo'), 'avatar.jpg');
```

---

## Directory Operations

### `files()`

Get all files in a directory.

```php
Storage::disk('s3-store-doc')->files(string $directory = null, bool $recursive = false): array
```

**Parameters:**
- `$directory` - Directory path (null for root)
- `$recursive` - Include subdirectories

**Returns:** `array` - Array of file paths

**Example:**
```php
$files = Storage::disk('s3-store-doc')->files('documents');
$allFiles = Storage::disk('s3-store-doc')->files('documents', true);
```

---

### `allFiles()`

Get all files from a directory recursively.

```php
Storage::disk('s3-store-doc')->allFiles(string $directory = null): array
```

**Parameters:**
- `$directory` - Directory path (null for root)

**Returns:** `array` - Array of file paths

**Example:**
```php
$allFiles = Storage::disk('s3-store-doc')->allFiles('uploads');
```

---

### `directories()`

Get all directories within a directory.

```php
Storage::disk('s3-store-doc')->directories(string $directory = null, bool $recursive = false): array
```

**Parameters:**
- `$directory` - Directory path (null for root)
- `$recursive` - Include subdirectories

**Returns:** `array` - Array of directory paths

**Example:**
```php
$dirs = Storage::disk('s3-store-doc')->directories('uploads');
```

---

### `allDirectories()`

Get all directories within a directory recursively.

```php
Storage::disk('s3-store-doc')->allDirectories(string $directory = null): array
```

**Parameters:**
- `$directory` - Directory path (null for root)

**Returns:** `array` - Array of directory paths

**Example:**
```php
$allDirs = Storage::disk('s3-store-doc')->allDirectories('uploads');
```

---

### `makeDirectory()`

Create a directory.

```php
Storage::disk('s3-store-doc')->makeDirectory(string $path): bool
```

**Parameters:**
- `$path` - Directory path

**Returns:** `bool` - True on success

**Example:**
```php
Storage::disk('s3-store-doc')->makeDirectory('uploads/2024');
```

---

### `deleteDirectory()`

Recursively delete a directory.

```php
Storage::disk('s3-store-doc')->deleteDirectory(string $directory): bool
```

**Parameters:**
- `$directory` - Directory path

**Returns:** `bool` - True on success

**Example:**
```php
Storage::disk('s3-store-doc')->deleteDirectory('old-uploads');
```

---

## File Information

### `exists()`

Determine if a file exists.

```php
Storage::disk('s3-store-doc')->exists(string $path): bool
```

**Parameters:**
- `$path` - File path

**Returns:** `bool` - True if exists

**Example:**
```php
if (Storage::disk('s3-store-doc')->exists('file.pdf')) {
    // File exists
}
```

---

### `missing()`

Determine if a file is missing.

```php
Storage::disk('s3-store-doc')->missing(string $path): bool
```

**Parameters:**
- `$path` - File path

**Returns:** `bool` - True if missing

**Example:**
```php
if (Storage::disk('s3-store-doc')->missing('file.pdf')) {
    // File doesn't exist
}
```

---

### `size()`

Get the file size in bytes.

```php
Storage::disk('s3-store-doc')->size(string $path): int
```

**Parameters:**
- `$path` - File path

**Returns:** `int` - File size in bytes

**Example:**
```php
$sizeInBytes = Storage::disk('s3-store-doc')->size('document.pdf');
$sizeInMB = $sizeInBytes / 1024 / 1024;
```

---

### `mimeType()`

Get the MIME type of a file.

```php
Storage::disk('s3-store-doc')->mimeType(string $path): string
```

**Parameters:**
- `$path` - File path

**Returns:** `string` - MIME type

**Example:**
```php
$mimeType = Storage::disk('s3-store-doc')->mimeType('image.jpg');
// Returns: 'image/jpeg'
```

---

### `lastModified()`

Get the file's last modification timestamp.

```php
Storage::disk('s3-store-doc')->lastModified(string $path): int
```

**Parameters:**
- `$path` - File path

**Returns:** `int` - Unix timestamp

**Example:**
```php
$timestamp = Storage::disk('s3-store-doc')->lastModified('file.txt');
$date = date('Y-m-d H:i:s', $timestamp);
```

---

### `checksum()`

Get the checksum/hash for a file.

```php
Storage::disk('s3-store-doc')->checksum(string $path, array $options = []): string|false
```

**Parameters:**
- `$path` - File path
- `$options` - Options array with `checksum_algorithm` key (md5, sha1, sha256)

**Returns:** `string|false` - Checksum hash or false

**Example:**
```php
$md5 = Storage::disk('s3-store-doc')->checksum('file.zip');
$sha256 = Storage::disk('s3-store-doc')->checksum('file.zip', ['checksum_algorithm' => 'sha256']);
```

---

### `path()`

Get the full path/URL for a file.

```php
Storage::disk('s3-store-doc')->path(string $path): string
```

**Parameters:**
- `$path` - File path

**Returns:** `string` - Full URL

**Example:**
```php
$url = Storage::disk('s3-store-doc')->path('documents/file.pdf');
```

---

## URL Generation

### `url()`

Get the URL for a file.

```php
Storage::disk('s3-store-doc')->url(string $path): string
```

**Parameters:**
- `$path` - File path

**Returns:** `string` - Public URL

**Example:**
```php
$url = Storage::disk('s3-store-doc')->url('images/photo.jpg');
```

---

### `temporaryUrl()`

Get a temporary URL with expiration.

```php
Storage::disk('s3-store-doc')->temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
```

**Parameters:**
- `$path` - File path
- `$expiration` - Expiration datetime
- `$options` - Additional options

**Returns:** `string` - Temporary URL

**Example:**
```php
$url = Storage::disk('s3-store-doc')->temporaryUrl(
    'private/document.pdf',
    now()->addMinutes(5)
);
```

---

### `downloadUrl()`

Get a URL that forces download.

```php
Storage::disk('s3-store-doc')->downloadUrl(string $path, string $filename = null): string
```

**Parameters:**
- `$path` - File path
- `$filename` - Optional custom filename

**Returns:** `string` - Download URL

**Example:**
```php
$url = Storage::disk('s3-store-doc')->downloadUrl('file.pdf', 'report.pdf');
```

---

### `previewUrl()`

Get a URL for inline preview (images, PDFs).

```php
Storage::disk('s3-store-doc')->previewUrl(string $path, string $filename = null): string
```

**Parameters:**
- `$path` - File path
- `$filename` - Optional filename

**Returns:** `string` - Preview URL

**Example:**
```php
$url = Storage::disk('s3-store-doc')->previewUrl('images/photo.jpg');
```

---

## Response Generation

### `download()`

Create a streamed download response.

```php
Storage::disk('s3-store-doc')->download(string $path, string $name = null, array $headers = []): StreamedResponse
```

**Parameters:**
- `$path` - File path
- `$name` - Download filename
- `$headers` - Additional HTTP headers

**Returns:** `StreamedResponse` - Download response

**Example:**
```php
return Storage::disk('s3-store-doc')->download('file.pdf', 'report.pdf');
```

---

### `response()`

Create a streamed response for inline display.

```php
Storage::disk('s3-store-doc')->response(string $path, string $name = null, array $headers = []): StreamedResponse
```

**Parameters:**
- `$path` - File path
- `$name` - Display filename
- `$headers` - Additional HTTP headers

**Returns:** `StreamedResponse` - Inline response

**Example:**
```php
// Preview image in browser
return Storage::disk('s3-store-doc')->response('images/photo.jpg');

// Preview PDF in browser
return Storage::disk('s3-store-doc')->response('documents/report.pdf');
```

---

## Advanced Operations

### Visibility

```php
// Set visibility
Storage::disk('s3-store-doc')->setVisibility('file.jpg', 'public');

// Get visibility
$visibility = Storage::disk('s3-store-doc')->visibility('file.jpg');
```

### Working with Streams

```php
// Read as stream
$stream = Storage::disk('s3-store-doc')->readStream('large-file.zip');

// Write from stream
$stream = fopen('local-file.zip', 'r');
Storage::disk('s3-store-doc')->writeStream('remote-file.zip', $stream);
fclose($stream);
```

### Batch Operations

```php
// Upload multiple files
foreach ($request->allFiles() as $file) {
    Storage::disk('s3-store-doc')->putFile('uploads', $file);
}

// Delete multiple files
Storage::disk('s3-store-doc')->delete([
    'file1.jpg',
    'file2.jpg',
    'file3.jpg',
]);
```

---

## Exception Handling

All methods may throw exceptions. Wrap in try-catch for production:

```php
try {
    $contents = Storage::disk('s3-store-doc')->get('file.pdf');
} catch (\Exception $e) {
    Log::error('Failed to retrieve file: ' . $e->getMessage());
    // Handle error
}
```

---

## See Also

- [README.md](README.md) - Installation and basic usage
- [EXAMPLES.md](EXAMPLES.md) - Practical examples
- [BUCKET_FOLDER_GUIDE.md](BUCKET_FOLDER_GUIDE.md) - Bucket and folder management
