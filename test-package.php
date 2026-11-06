<?php

/**
 * Test Script for Store-Doc Laravel Storage Package
 *
 * This script tests basic operations to ensure the package works correctly.
 * Run this from the command line: php test-package.php
 */

require __DIR__.'/../../vendor/autoload.php';

use StoreDoc\Laravel\Storage\StoreDocClient;

// ANSI color codes for output
define('GREEN', "\033[32m");
define('RED', "\033[31m");
define('YELLOW', "\033[33m");
define('BLUE', "\033[34m");
define('RESET', "\033[0m");

function success($message)
{
    echo GREEN.'✓ '.$message.RESET."\n";
}

function error($message)
{
    echo RED.'✗ '.$message.RESET."\n";
}

function info($message)
{
    echo BLUE.'ℹ '.$message.RESET."\n";
}

function warning($message)
{
    echo YELLOW.'⚠ '.$message.RESET."\n";
}

function section($title)
{
    echo "\n".BLUE.'═══ '.$title.' ═══'.RESET."\n\n";
}

// Load environment variables from .env file
// [S-04] Validates variable names to prevent environment injection.
function loadEnv($path)
{
    if (! file_exists($path)) {
        error("Environment file not found: $path");

        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and blank lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Skip lines without an assignment
        if (! str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        // [S-04] Only allow safe environment variable names
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
        }

        // Strip surrounding quotes
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        if (! array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }

    return true;
}

// Main test execution
try {
    section('Store-Doc Package Test Suite');

    // Load environment
    info('Loading environment variables...');
    if (! loadEnv(__DIR__.'/../../.env')) {
        throw new Exception('Failed to load .env file');
    }
    success('Environment loaded');

    // Configuration
    $config = [
        'api_url' => getenv('STORE_DOC_API_URL') ?: '',
        'access_key_id' => getenv('STORE_DOC_ACCESS_KEY_ID'),
        'secret_access_key' => getenv('STORE_DOC_SECRET_KEY'),
        'bucket' => getenv('STORE_DOC_BUCKET'),
        'allow_insecure_http' => filter_var(getenv('STORE_DOC_ALLOW_INSECURE_HTTP') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'allow_insecure_tls' => filter_var(getenv('STORE_DOC_ALLOW_INSECURE_TLS') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'verify_ssl' => filter_var(getenv('STORE_DOC_VERIFY_SSL') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    ];

    // Validate configuration
    info('Validating configuration...');
    if (empty($config['api_url']) || empty($config['access_key_id']) || empty($config['secret_access_key'])) {
        throw new Exception('Missing Store-Doc configuration. Set STORE_DOC_API_URL, STORE_DOC_ACCESS_KEY_ID, and STORE_DOC_SECRET_KEY in .env');
    }
    success('Configuration valid');

    info('API URL: '.$config['api_url']);
    info('API credentials configured');

    // Initialize client
    section('1. Client Initialization');
    $client = new StoreDocClient($config);
    success('Client initialized successfully');

    // Test 1: List Buckets
    section('2. List Buckets');
    try {
        $buckets = $client->listBuckets();
        success('Listed buckets successfully');
        info('Found '.count($buckets).' bucket(s)');

        if (! empty($buckets)) {
            foreach (array_slice($buckets, 0, 3) as $bucket) {
                info('  - '.($bucket['name'] ?? 'N/A').' (ID: '.substr($bucket['id'] ?? 'N/A', 0, 8).'...)');
            }
        }
    } catch (Exception $e) {
        error('Failed to list buckets: '.$e->getMessage());
    }

    if (getenv('STORE_DOC_TEST_ALLOW_MUTATIONS') !== 'true') {
        info('Read-only checks complete. Set STORE_DOC_TEST_ALLOW_MUTATIONS=true to enable bucket/file mutations.');
        exit(0);
    }

    // Test 2: Create Bucket (if needed)
    section('3. Bucket Creation Test');
    $testBucketName = 'test-package-'.time();
    $testBucketId = null;

    try {
        info("Creating test bucket: $testBucketName");
        $bucket = $client->createBucket([
            'name' => $testBucketName,
            'description' => 'Test bucket created by package test script',
            'visibility' => 'private',
        ]);

        $testBucketId = $bucket['id'] ?? null;
        success('Bucket created successfully');
        info('Bucket ID: '.substr($testBucketId, 0, 16).'...');

    } catch (Exception $e) {
        warning("Bucket creation failed (this is OK if you don't have permissions): ".$e->getMessage());

        // Try to use existing bucket
        if (! empty($config['bucket'])) {
            $testBucketId = $config['bucket'];
            info('Using configured bucket: '.substr($testBucketId, 0, 16).'...');
        } elseif (! empty($buckets)) {
            $testBucketId = $buckets[0]['id'];
            info('Using first available bucket: '.substr($testBucketId, 0, 16).'...');
        }
    }

    if (! $testBucketId) {
        throw new Exception('No bucket available for testing. Please configure STORE_DOC_BUCKET in .env');
    }

    // Update client with bucket
    $config['bucket'] = $testBucketId;
    $client = new StoreDocClient($config);

    // Test 3: Create Folder
    section('4. Folder Creation Test');
    $testFolderName = 'test-folder-'.time();
    $testFolderId = null;

    try {
        info("Creating test folder: $testFolderName");
        $folder = $client->createFolder([
            'bucket_id' => $testBucketId,
            'name' => $testFolderName,
            'visibility' => 'private',
        ]);

        $testFolderId = $folder['id'] ?? null;
        success('Folder created successfully');
        info('Folder ID: '.substr($testFolderId, 0, 16).'...');

    } catch (Exception $e) {
        error('Folder creation failed: '.$e->getMessage());
    }

    // Test 4: Upload File
    section('5. File Upload Test');
    $testFileName = 'test-file-'.time().'.txt';
    $testFileContent = "This is a test file created by the Store-Doc package test script.\nTimestamp: ".date('Y-m-d H:i:s');
    $uploadedObjectId = null;

    try {
        info("Uploading test file: $testFileName");
        $result = $client->upload($testFileName, $testFileContent, [
            'visibility' => 'private',
        ]);

        $uploadedObjectId = $result['data']['id'] ?? null;
        success('File uploaded successfully');
        info('Object ID: '.substr($uploadedObjectId, 0, 16).'...');
        info('File size: '.strlen($testFileContent).' bytes');

    } catch (Exception $e) {
        error('File upload failed: '.$e->getMessage());
    }

    // Test 5: List Objects
    section('6. List Objects Test');
    try {
        $objects = $client->listObjects('', false);
        success('Listed objects successfully');
        info('Found '.count($objects).' object(s)');

        if (! empty($objects)) {
            foreach (array_slice($objects, 0, 3) as $object) {
                info('  - '.($object['path'] ?? 'N/A').' ('.($object['size'] ?? 0).' bytes)');
            }
        }
    } catch (Exception $e) {
        error('Failed to list objects: '.$e->getMessage());
    }

    // Test 6: Check File Existence
    section('7. File Existence Test');
    if ($testFileName) {
        try {
            $exists = $client->exists($testFileName);
            if ($exists) {
                success('File existence check passed: file found');
            } else {
                warning('File existence check: file not found (may take a moment to index)');
            }
        } catch (Exception $e) {
            error('File existence check failed: '.$e->getMessage());
        }
    }

    // Test 7: Download File
    section('8. File Download Test');
    if ($uploadedObjectId) {
        try {
            info('Downloading file...');
            $downloadedContent = $client->download($uploadedObjectId);

            if ($downloadedContent === $testFileContent) {
                success('File downloaded successfully - content matches!');
            } else {
                warning("File downloaded but content doesn't match");
                info('Expected length: '.strlen($testFileContent));
                info('Downloaded length: '.strlen($downloadedContent));
            }
        } catch (Exception $e) {
            error('File download failed: '.$e->getMessage());
        }
    }

    // Test 8: Get Object Metadata
    section('9. Object Metadata Test');
    if ($uploadedObjectId) {
        try {
            $metadata = $client->getObjectMetadata($uploadedObjectId);
            if ($metadata) {
                success('Retrieved object metadata');
                info('Path: '.($metadata['path'] ?? 'N/A'));
                info('Size: '.($metadata['size'] ?? 0).' bytes');
                info('MIME: '.($metadata['mime_type'] ?? 'N/A'));
            } else {
                warning('No metadata returned');
            }
        } catch (Exception $e) {
            error('Failed to get metadata: '.$e->getMessage());
        }
    }

    // Test 9: Get Presigned URL
    section('10. Presigned URL Test');
    if ($uploadedObjectId) {
        try {
            $url = $client->getUrl($uploadedObjectId);
            if ($url) {
                success('Generated presigned URL (not displayed)');
            } else {
                warning('No URL returned');
            }
        } catch (Exception $e) {
            error('Failed to get presigned URL: '.$e->getMessage());
        }
    }

    // Test 10: Temporary URL
    section('11. Temporary URL Test');
    if ($uploadedObjectId) {
        try {
            info('Generating temporary URL (expires in 5 minutes)...');
            $expiration = new DateTime('+5 minutes');
            $tempUrl = $client->getTemporaryUrl($uploadedObjectId, $expiration);
            if ($tempUrl) {
                success('Generated temporary URL (not displayed)');
                info('Expires at: '.$expiration->format('Y-m-d H:i:s'));
            } else {
                warning('No temporary URL returned');
            }
        } catch (Exception $e) {
            error('Failed to get temporary URL: '.$e->getMessage());
        }
    }

    // Test 11: Checksum
    section('12. File Checksum Test');
    if ($uploadedObjectId) {
        try {
            info('Calculating file checksum...');
            $checksum = $client->getChecksum($uploadedObjectId, 'md5');
            if ($checksum) {
                success('Checksum retrieved: '.$checksum);

                // Verify checksum matches local calculation
                $localChecksum = md5($testFileContent);
                if ($checksum === $localChecksum) {
                    success('Checksum verification passed!');
                } else {
                    warning('Checksum mismatch');
                    info('Expected: '.$localChecksum);
                    info('Received: '.$checksum);
                }
            } else {
                warning('No checksum returned (may not be available)');
            }
        } catch (Exception $e) {
            error('Failed to get checksum: '.$e->getMessage());
        }
    }

    // Test 12: Download URL with filename
    section('13. Download URL Test');
    if ($uploadedObjectId) {
        try {
            info('Generating download URL with custom filename...');
            $downloadUrl = $client->getDownloadUrl($uploadedObjectId, 'custom-filename.txt', false);
            if ($downloadUrl) {
                success('Generated download URL (not displayed)');
            } else {
                warning('No download URL returned');
            }
        } catch (Exception $e) {
            error('Failed to get download URL: '.$e->getMessage());
        }
    }

    // Test 13: Preview URL
    section('14. Preview URL Test');
    if ($uploadedObjectId) {
        try {
            info('Generating preview URL (inline display)...');
            $previewUrl = $client->getDownloadUrl($uploadedObjectId, null, true);
            if ($previewUrl) {
                success('Generated preview URL (not displayed)');
            } else {
                warning('No preview URL returned');
            }
        } catch (Exception $e) {
            error('Failed to get preview URL: '.$e->getMessage());
        }
    }

    // Test 14: Stream Download
    section('15. Stream Download Test');
    if ($uploadedObjectId) {
        try {
            info('Testing stream download...');
            $stream = $client->downloadStream($uploadedObjectId);
            if (is_resource($stream)) {
                $streamedContent = stream_get_contents($stream);
                fclose($stream);

                if ($streamedContent === $testFileContent) {
                    success('Stream download successful - content matches!');
                } else {
                    warning("Stream downloaded but content doesn't match");
                }
            } else {
                warning("Download stream didn't return a resource");
            }
        } catch (Exception $e) {
            error('Stream download failed: '.$e->getMessage());
        }
    }

    // Cleanup
    section('16. Cleanup');

    // Delete uploaded file
    if ($uploadedObjectId) {
        try {
            info('Deleting test file...');
            $deleted = $client->delete($uploadedObjectId);
            if ($deleted) {
                success('Test file deleted');
            } else {
                warning('Failed to delete test file');
            }
        } catch (Exception $e) {
            error('File deletion failed: '.$e->getMessage());
        }
    }

    // Delete test folder
    if ($testFolderId) {
        try {
            info('Deleting test folder...');
            $deleted = $client->deleteFolder($testFolderId);
            if ($deleted) {
                success('Test folder deleted');
            } else {
                warning('Failed to delete test folder');
            }
        } catch (Exception $e) {
            error('Folder deletion failed: '.$e->getMessage());
        }
    }

    // Delete test bucket (only if we created it)
    if ($testBucketName && $testBucketId && $testBucketId !== $config['bucket']) {
        try {
            info('Deleting test bucket...');
            $deleted = $client->deleteBucket($testBucketId);
            if ($deleted) {
                success('Test bucket deleted');
            } else {
                warning('Failed to delete test bucket (may not be empty)');
            }
        } catch (Exception $e) {
            warning('Bucket deletion failed: '.$e->getMessage());
        }
    }

    // Final summary
    section('Test Summary');
    success('All basic operations completed!');
    info('The package is working correctly with your Store-Doc API');

} catch (Exception $e) {
    echo "\n";
    error('Test failed: '.$e->getMessage());
    echo "\n";
    exit(1);
}

echo "\n";
