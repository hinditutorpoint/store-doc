# Quick Start - Get Your Test Credentials

Run these commands to get the information needed for testing:

## Step 1: Get API Key Details

```bash
php artisan tinker
```

Then paste this:

```php
$key = App\Models\StorageApiKey::first();
echo "Access Key ID: " . $key->access_key_id . PHP_EOL;
echo "Secret (hashed, need to regenerate): Use the regenerate button in UI" . PHP_EOL;
```

## Step 2: Get Bucket ID

Still in tinker:

```php
$bucket = App\Models\StorageBucket::first();
echo "Bucket ID: " . $bucket->id . PHP_EOL;
echo "Bucket Name: " . $bucket->name . PHP_EOL;
```

## Step 3: Get Secret Key

The secret key is hashed in the database. You need to either:

**Option A: Use the UI**
1. Go to http://localhost:8000 (or your app URL)
2. Navigate to API Keys
3. Click "Regenerate Secret" on your API key
4. Copy the new secret key shown

**Option B: Create a new API key via tinker**

```php
$user = App\Models\User::first();
$apiKey = App\Models\StorageApiKey::create([
    'user_id' => $user->id,
    'name' => 'Test Key',
    'access_key_id' => 'sk_test_' . bin2hex(random_bytes(16)),
    'secret_access_key_hash' => hash('sha256', $secret = bin2hex(random_bytes(32))),
    'status' => 'active',
]);

echo "Access Key ID: " . $apiKey->access_key_id . PHP_EOL;
echo "Secret Access Key: " . $secret . PHP_EOL;
echo "SAVE THIS SECRET - It won't be shown again!" . PHP_EOL;
```

## Step 4: Update test-simple.php

Edit `packages/s3-store-doc/test-simple.php`:

```php
$config = [
    'api_url' => 'http://localhost:8000/api',
    'access_key_id' => 'YOUR_ACCESS_KEY_FROM_ABOVE',
    'secret_access_key' => 'YOUR_SECRET_FROM_ABOVE',
    'bucket' => 'YOUR_BUCKET_ID_FROM_ABOVE',
    'allow_insecure_http' => true, // Local development only
];
```

## Step 5: Run the Test

```bash
cd packages/s3-store-doc
php test-simple.php
```

You should see:
```
✅ ALL TESTS PASSED!
The package is working correctly!
```
