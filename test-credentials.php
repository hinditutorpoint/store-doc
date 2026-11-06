<?php

/**
 * Optional live Store-Doc smoke test. Use dedicated, least-privilege test credentials.
 *
 * This script tests the package directly with Laravel autoload
 */

// Use main Laravel app's autoload
require __DIR__.'/../../vendor/autoload.php';

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use StoreDoc\Laravel\Storage\StoreDocAdapter;
use StoreDoc\Laravel\Storage\StoreDocClient;

$config = [
    'api_url' => getenv('STORE_DOC_API_URL') ?: '',
    'access_key_id' => getenv('STORE_DOC_ACCESS_KEY_ID') ?: '',
    'secret_access_key' => getenv('STORE_DOC_SECRET_KEY') ?: '',
    'bucket' => getenv('STORE_DOC_BUCKET') ?: '',
    'timeout' => (int) (getenv('STORE_DOC_TIMEOUT') ?: 30),
    'verify_ssl' => filter_var(getenv('STORE_DOC_VERIFY_SSL') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'allow_insecure_http' => filter_var(getenv('STORE_DOC_ALLOW_INSECURE_HTTP') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'allow_insecure_tls' => filter_var(getenv('STORE_DOC_ALLOW_INSECURE_TLS') ?: 'false', FILTER_VALIDATE_BOOLEAN),
];

foreach (['api_url', 'access_key_id', 'secret_access_key', 'bucket'] as $required) {
    if ($config[$required] === '') {
        fwrite(STDERR, "Set STORE_DOC_API_URL, STORE_DOC_ACCESS_KEY_ID, STORE_DOC_SECRET_KEY, and STORE_DOC_BUCKET first.\n");
        exit(1);
    }
}

echo "========================================\n";
echo "Store-Doc Package Test\n";
echo "========================================\n\n";

// Test 1: Client Connection
echo "Test 1: Testing Client Connection...\n";
try {
    $client = new StoreDocClient($config);
    echo "✅ Client created successfully\n\n";
} catch (\Exception $e) {
    echo '❌ Client creation failed: '.$e->getMessage()."\n\n";
    exit(1);
}

// Test 2: List Buckets
echo "Test 2: Listing Buckets...\n";
try {
    $buckets = $client->listBuckets();
    echo '✅ Found '.count($buckets)." bucket(s)\n";
    if (! empty($buckets)) {
        foreach ($buckets as $bucket) {
            echo '   - '.($bucket['name'] ?? 'unnamed').' (ID: '.($bucket['id'] ?? 'no-id').")\n";
        }
    }
    echo "\n";
} catch (\Exception $e) {
    echo '❌ List buckets failed: '.$e->getMessage()."\n\n";
}

// Test 3: Get Specific Bucket
echo "Test 3: Getting Bucket Info...\n";
try {
    $bucket = $client->getBucket($config['bucket']);
    if ($bucket) {
        echo '✅ Bucket found: '.($bucket['name'] ?? 'unnamed')."\n";
        echo '   Status: '.($bucket['status'] ?? 'unknown')."\n";
        echo '   Total Files: '.($bucket['total_files'] ?? 0)."\n";
        echo '   Total Size: '.($bucket['total_size'] ?? 0)." bytes\n";
    } else {
        echo "❌ Bucket not found\n";
    }
    echo "\n";
} catch (\Exception $e) {
    echo '❌ Get bucket failed: '.$e->getMessage()."\n\n";
}

// Test 4: List Objects in Bucket
echo "Test 4: Listing Objects in Bucket...\n";
try {
    $objects = $client->listObjects('', false);
    echo '✅ Found '.count($objects)." object(s)\n";
    if (! empty($objects)) {
        $count = 0;
        foreach ($objects as $object) {
            if ($count < 5) { // Show first 5 only
                echo '   - '.($object['name'] ?? 'unnamed').' ('.($object['size'] ?? 0)." bytes)\n";
                $count++;
            }
        }
        if (count($objects) > 5) {
            echo '   ... and '.(count($objects) - 5)." more\n";
        }
    }
    echo "\n";
} catch (\Exception $e) {
    echo '❌ List objects failed: '.$e->getMessage()."\n\n";
}

if (getenv('STORE_DOC_TEST_ALLOW_MUTATIONS') !== 'true') {
    echo "Read-only checks complete. Set STORE_DOC_TEST_ALLOW_MUTATIONS=true to enable upload/delete checks.\n";
    exit(0);
}

// Test 5: Create Adapter and Upload Test File
echo "Test 5: Testing File Upload...\n";
try {
    $adapter = new StoreDocAdapter($config);
    $filesystem = new Filesystem($adapter);

    $testPath = 'test-package/test-'.time().'.txt';
    $testContent = 'Package test at '.date('Y-m-d H:i:s');

    echo "   Uploading to: {$testPath}\n";

    $flysystemConfig = new Config;
    $adapter->write($testPath, $testContent, $flysystemConfig);

    echo "✅ Upload successful\n\n";

    // Test 6: Check if file exists
    echo "Test 6: Checking File Exists...\n";
    if ($adapter->fileExists($testPath)) {
        echo "✅ File exists check passed\n\n";

        // Test 7: Read file content
        echo "Test 7: Reading File Content...\n";
        $readContent = $adapter->read($testPath);
        if ($readContent === $testContent) {
            echo "✅ Content matches: {$readContent}\n\n";
        } else {
            echo "❌ Content mismatch\n";
            echo "   Expected: {$testContent}\n";
            echo "   Got: {$readContent}\n\n";
        }

        // Test 8: Get file size
        echo "Test 8: Getting File Size...\n";
        try {
            $fileAttrs = $adapter->fileSize($testPath);
            echo '✅ File size: '.$fileAttrs->fileSize()." bytes\n\n";
        } catch (\Exception $e) {
            echo '❌ Get size failed: '.$e->getMessage()."\n\n";
        }

        // Test 9: Get file URL
        echo "Test 9: Getting File URL...\n";
        try {
            $url = $adapter->publicUrl($testPath, $flysystemConfig);
            echo "✅ URL generated successfully (not displaying signed URL)\n\n";
        } catch (\Exception $e) {
            echo '❌ URL generation failed: '.$e->getMessage()."\n\n";
        }

        // Test 10: Delete file
        echo "Test 10: Deleting Test File...\n";
        try {
            $adapter->delete($testPath);
            echo "✅ Delete successful\n\n";

            // Verify deleted
            if (! $adapter->fileExists($testPath)) {
                echo "✅ Verified: File no longer exists\n\n";
            } else {
                echo "❌ File still exists after delete\n\n";
            }
        } catch (\Exception $e) {
            echo '❌ Delete failed: '.$e->getMessage()."\n\n";
        }

    } else {
        echo "❌ File exists check failed\n\n";
    }

} catch (\Exception $e) {
    echo '❌ Upload failed: '.$e->getMessage()."\n";
    echo '   Error details: '.$e->getTraceAsString()."\n\n";
}

echo "========================================\n";
echo "Test Complete!\n";
echo "========================================\n";
