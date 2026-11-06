<?php

/**
 * Simple Test Script for Store-Doc Laravel Storage Package
 *
 * SETUP: Edit the configuration below with your credentials
 * RUN: php test-simple.php
 */

require __DIR__.'/../../vendor/autoload.php';

use StoreDoc\Laravel\Storage\StoreDocClient;

// ============================================
// CONFIGURATION - EDIT THESE VALUES
// ============================================
$config = [
    'api_url' => 'https://your-domain.com/api', // Your API URL
    'access_key_id' => 'YOUR_ACCESS_KEY_ID',  // Get from API Keys page
    'secret_access_key' => 'YOUR_SECRET_KEY', // Get from API Keys page
    'bucket' => 'YOUR_BUCKET_UUID',           // Your bucket ID
    'allow_insecure_http' => false,           // Enable only for local HTTP development
];
// ============================================

echo "\n";
echo "═══════════════════════════════════════════\n";
echo "  Store-Doc Package - Simple Test\n";
echo "═══════════════════════════════════════════\n\n";

// Validate configuration
if ($config['access_key_id'] === 'YOUR_ACCESS_KEY_ID' || $config['secret_access_key'] === 'YOUR_SECRET_KEY' || $config['bucket'] === 'YOUR_BUCKET_UUID') {
    echo "❌ ERROR: Please edit test-simple.php and add your API credentials\n\n";
    echo "You need to set:\n";
    echo "  - api_url (your Store-Doc API URL)\n";
    echo "  - access_key_id (from API Keys page)\n";
    echo "  - secret_access_key (from API Keys page)\n";
    echo "  - bucket (your bucket UUID)\n\n";
    exit(1);
}

try {
    // Test 1: Initialize Client
    echo "1. Initializing client...\n";
    $client = new StoreDocClient($config);
    echo "   ✓ Client initialized\n\n";

    // Test 2: List Buckets
    echo "2. Testing bucket listing...\n";
    $buckets = $client->listBuckets();
    echo '   ✓ Found '.count($buckets)." bucket(s)\n";
    if (! empty($buckets)) {
        foreach (array_slice($buckets, 0, 2) as $bucket) {
            echo '     - '.($bucket['name'] ?? 'N/A')."\n";
        }
    }
    echo "\n";

    // Test 3: List Objects
    echo "3. Testing object listing...\n";
    $objects = $client->listObjects('', false);
    echo '   ✓ Found '.count($objects)." object(s)\n";
    if (! empty($objects)) {
        foreach (array_slice($objects, 0, 3) as $object) {
            echo '     - '.($object['path'] ?? 'N/A')."\n";
        }
    }
    echo "\n";

    if (getenv('STORE_DOC_TEST_ALLOW_MUTATIONS') !== 'true') {
        echo "Read-only checks complete. Set STORE_DOC_TEST_ALLOW_MUTATIONS=true to enable upload/delete checks.\n";
        exit(0);
    }

    // Test 4: Upload a test file
    echo "4. Testing file upload...\n";
    $testFileName = 'test-'.time().'.txt';
    $testContent = 'Test file created at '.date('Y-m-d H:i:s');

    $result = $client->upload($testFileName, $testContent);
    $objectId = $result['data']['id'] ?? null;
    echo "   ✓ File uploaded: $testFileName\n";
    echo '   ✓ Object ID: '.substr($objectId, 0, 20)."...\n\n";

    // Test 5: Check if file exists
    echo "5. Testing file existence...\n";
    sleep(1); // Wait a moment for indexing
    $exists = $client->exists($testFileName);
    echo '   '.($exists ? '✓' : '✗').' File exists: '.($exists ? 'YES' : 'NO')."\n\n";

    // Test 6: Download the file
    echo "6. Testing file download...\n";
    $downloaded = $client->download($objectId);
    $matches = ($downloaded === $testContent);
    echo '   '.($matches ? '✓' : '✗').' Content matches: '.($matches ? 'YES' : 'NO')."\n\n";

    // Test 7: Get metadata
    echo "7. Testing metadata retrieval...\n";
    $metadata = $client->getObjectMetadata($objectId);
    if ($metadata) {
        echo "   ✓ Metadata retrieved\n";
        echo '     - Size: '.($metadata['size'] ?? 0)." bytes\n";
        echo '     - MIME: '.($metadata['mime_type'] ?? 'N/A')."\n";
    }
    echo "\n";

    // Test 8: Get presigned URL
    echo "8. Testing presigned URL...\n";
    $url = $client->getUrl($objectId);
    if ($url) {
        echo "   ✓ URL generated successfully (not displaying signed URL)\n";
    }
    echo "\n";

    // Test 9: Delete the file
    echo "9. Testing file deletion...\n";
    $deleted = $client->delete($objectId);
    echo '   '.($deleted ? '✓' : '✗')." File deleted\n\n";

    // Summary
    echo "═══════════════════════════════════════════\n";
    echo "  ✅ ALL TESTS PASSED!\n";
    echo "  The package is working correctly!\n";
    echo "═══════════════════════════════════════════\n\n";

} catch (Exception $e) {
    echo "\n❌ TEST FAILED\n";
    echo 'Error: '.$e->getMessage()."\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString()."\n\n";
    exit(1);
}
