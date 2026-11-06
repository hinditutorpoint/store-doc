# Bucket and Folder Management Guide

This guide explains how to create and manage buckets and folders using the Store-Doc Laravel Storage Driver.

## Creating Buckets

Buckets are the top-level containers for your files. You can create buckets programmatically using the client.

### Basic Bucket Creation

```php
use StoreDoc\Laravel\Storage\StoreDocClient;

$client = new StoreDocClient([
    'api_url' => env('STORE_DOC_API_URL'),
    'access_key_id' => env('STORE_DOC_ACCESS_KEY_ID'),
    'secret_access_key' => env('STORE_DOC_SECRET_KEY'),
    'bucket' => '', // Not needed for bucket creation
]);

// Create a simple bucket
$bucket = $client->createBucket([
    'name' => 'my-app-uploads',
    'description' => 'Application file uploads',
]);

echo "Bucket created with ID: " . $bucket['id'];
```

### Advanced Bucket Creation with All Options

```php
$bucket = $client->createBucket([
    // Required
    'name' => 'my-secure-bucket',
    
    // Optional settings
    'description' => 'Secure storage for sensitive documents',
    'region' => 'us-east-1', // Options: us-east-1, us-west-1, us-west-2, eu-west-1, eu-central-1, ap-southeast-1, ap-northeast-1
    'visibility' => 'private', // Options: private, public, authenticated
    'versioning_enabled' => true, // Enable file versioning
    'encryption_enabled' => true, // Enable encryption at rest
    
    // CORS configuration
    'cors_rules' => [
        [
            'allowed_origins' => ['https://example.com', 'https://app.example.com'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
            'allowed_headers' => ['Content-Type', 'Authorization'],
            'max_age_seconds' => 3600,
        ],
    ],
    
    // Tags for organization
    'tags' => [
        'environment' => 'production',
        'department' => 'engineering',
        'cost-center' => 'CC-1234',
    ],
]);
```

### Bucket Name Requirements

- **Length**: 3-63 characters
- **Format**: Lowercase letters, numbers, and hyphens only
- **Start/End**: Must start and end with a letter or number
- **Unique**: Must be unique within your account

**Valid Examples:**
- `my-app-uploads`
- `user-avatars-2024`
- `backup-files`

**Invalid Examples:**
- `My-App` (uppercase)
- `-my-app` (starts with hyphen)
- `my_app` (underscore not allowed)
- `ab` (too short)

## Creating Folders

Folders help organize files within buckets. They can be nested to create hierarchical structures.

### Basic Folder Creation

```php
// Create a root-level folder
$folder = $client->createFolder([
    'bucket_id' => 'bucket-uuid-here',
    'name' => 'Documents',
]);

echo "Folder created with ID: " . $folder['id'];
```

### Creating Nested Folders

```php
// Create parent folder
$parentFolder = $client->createFolder([
    'bucket_id' => 'bucket-uuid-here',
    'name' => 'Projects',
]);

// Create subfolder
$subfolder = $client->createFolder([
    'bucket_id' => 'bucket-uuid-here',
    'parent_id' => $parentFolder['id'],
    'name' => '2024',
]);

// Create nested subfolder
$nestedFolder = $client->createFolder([
    'bucket_id' => 'bucket-uuid-here',
    'parent_id' => $subfolder['id'],
    'name' => 'Q1',
]);

// Result: Projects/2024/Q1/
```

### Folder with Custom Settings

```php
$folder = $client->createFolder([
    'bucket_id' => 'bucket-uuid-here',
    'name' => 'Public Documents',
    'parent_id' => null, // Root level
    'visibility' => 'public', // Options: inherit, private, public, authenticated
    'metadata' => [
        'department' => 'Marketing',
        'created_by' => 'John Doe',
        'purpose' => 'Public documentation',
    ],
]);
```

### Folder Name Requirements

- **Length**: 1-255 characters
- **Forbidden Characters**: Cannot contain `/ \ : * ? " < > |`

**Valid Examples:**
- `Documents`
- `User Files`
- `2024-Reports`

**Invalid Examples:**
- `My/Folder` (contains /)
- `File:Name` (contains :)
- `Test?` (contains ?)

## Listing Buckets and Folders

### List All Buckets

```php
// Get all buckets
$buckets = $client->listBuckets();

foreach ($buckets as $bucket) {
    echo "Bucket: {$bucket['name']} (ID: {$bucket['id']})\n";
}

// List with filters
$buckets = $client->listBuckets([
    'search' => 'uploads',
    'visibility' => 'private',
    'sort_by' => 'created_at',
    'sort_order' => 'desc',
]);
```

### List Folders in a Bucket

```php
// Get root-level folders
$folders = $client->listFolders('bucket-uuid-here');

// Get subfolders
$subfolders = $client->listFolders('bucket-uuid-here', 'parent-folder-uuid');

foreach ($folders as $folder) {
    echo "Folder: {$folder['name']}\n";
}
```

## Getting Details

### Get Bucket Details

```php
$bucket = $client->getBucket('bucket-uuid-here');

echo "Name: {$bucket['name']}\n";
echo "Region: {$bucket['region']}\n";
echo "Total Files: {$bucket['total_files']}\n";
echo "Total Size: {$bucket['total_size']} bytes\n";
```

### Get Folder Details

```php
$folder = $client->getFolder('folder-uuid-here');

echo "Name: {$folder['name']}\n";
echo "Path: {$folder['path']}\n";
echo "Visibility: {$folder['visibility']}\n";
```

## Deleting Buckets and Folders

### Delete a Folder

```php
$success = $client->deleteFolder('folder-uuid-here');

if ($success) {
    echo "Folder deleted successfully";
}
```

### Delete a Bucket

```php
// Note: Bucket must be empty before deletion
$success = $client->deleteBucket('bucket-uuid-here');

if ($success) {
    echo "Bucket deleted successfully";
}
```

## Complete Example: Setting Up Storage Structure

```php
<?php

use StoreDoc\Laravel\Storage\StoreDocClient;

// Initialize client
$client = new StoreDocClient([
    'api_url' => 'https://api.store-doc.com/api',
    'access_key_id' => 'your-access-key-id',
    'secret_access_key' => 'your-secret-key',
    'bucket' => '', // Will be set after creation
]);

try {
    // 1. Create main bucket
    $bucket = $client->createBucket([
        'name' => 'my-app-storage',
        'description' => 'Main application storage',
        'visibility' => 'private',
        'versioning_enabled' => true,
        'encryption_enabled' => true,
    ]);
    
    echo "✓ Bucket created: {$bucket['name']}\n";
    $bucketId = $bucket['id'];
    
    // 2. Create folder structure
    $folders = [
        'uploads' => null,
        'documents' => null,
        'images' => null,
        'videos' => null,
    ];
    
    foreach ($folders as $name => &$id) {
        $folder = $client->createFolder([
            'bucket_id' => $bucketId,
            'name' => ucfirst($name),
        ]);
        $id = $folder['id'];
        echo "✓ Folder created: {$name}\n";
    }
    
    // 3. Create subfolders under images
    $imageSubfolders = ['avatars', 'products', 'banners'];
    
    foreach ($imageSubfolders as $subfolder) {
        $client->createFolder([
            'bucket_id' => $bucketId,
            'parent_id' => $folders['images'],
            'name' => ucfirst($subfolder),
        ]);
        echo "✓ Subfolder created: images/{$subfolder}\n";
    }
    
    echo "\n✅ Storage structure created successfully!\n";
    echo "Bucket ID: {$bucketId}\n";
    
} catch (\Exception $e) {
    echo "❌ Error: {$e->getMessage()}\n";
}
```

## Using with Laravel Storage Facade

Once you have your bucket created, you can use it with Laravel's Storage facade:

```php
// Update your .env
STORE_DOC_BUCKET=bucket-uuid-from-above

// Now use Storage facade
use Illuminate\Support\Facades\Storage;

// Upload to a specific folder
Storage::disk('s3-store-doc')->put('Documents/report.pdf', $contents);
Storage::disk('s3-store-doc')->put('Images/avatars/user-123.jpg', $image);
```

## Best Practices

### Bucket Organization

1. **Use separate buckets for different environments**
   ```php
   $prodBucket = $client->createBucket(['name' => 'myapp-production']);
   $devBucket = $client->createBucket(['name' => 'myapp-development']);
   ```

2. **Enable versioning for important data**
   ```php
   $bucket = $client->createBucket([
       'name' => 'critical-documents',
       'versioning_enabled' => true,
   ]);
   ```

3. **Use encryption for sensitive data**
   ```php
   $bucket = $client->createBucket([
       'name' => 'user-data',
       'encryption_enabled' => true,
   ]);
   ```

### Folder Organization

1. **Create logical folder hierarchies**
   ```
   uploads/
   ├── users/
   │   ├── avatars/
   │   └── documents/
   ├── products/
   │   ├── images/
   │   └── videos/
   └── temp/
   ```

2. **Use metadata for additional context**
   ```php
   $client->createFolder([
       'bucket_id' => $bucketId,
       'name' => 'Q1-2024',
       'metadata' => [
           'quarter' => '1',
           'year' => '2024',
           'owner' => 'finance-team',
       ],
   ]);
   ```

## Error Handling

```php
try {
    $bucket = $client->createBucket([
        'name' => 'my-bucket',
    ]);
} catch (\RuntimeException $e) {
    // Handle specific errors
    if (str_contains($e->getMessage(), 'already have a bucket')) {
        echo "Bucket name already exists";
    } else {
        echo "Error: {$e->getMessage()}";
    }
}
```

## Region Options

Available regions for bucket creation:

| Region Code | Location |
|------------|----------|
| `us-east-1` | US East (N. Virginia) |
| `us-west-1` | US West (N. California) |
| `us-west-2` | US West (Oregon) |
| `eu-west-1` | EU (Ireland) |
| `eu-central-1` | EU (Frankfurt) |
| `ap-southeast-1` | Asia Pacific (Singapore) |
| `ap-northeast-1` | Asia Pacific (Tokyo) |

Choose the region closest to your users for better performance.
