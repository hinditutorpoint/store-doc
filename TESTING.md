# Testing the Package

This guide explains how to test the Store-Doc Laravel Storage Package to ensure it's working correctly.

## Quick Test

### Step 1: Get Your API Credentials

1. Log in to your Store-Doc application
2. Navigate to **API Keys** section
3. Create a new API key or use an existing one
4. Copy the **Access Key ID** and **Secret Access Key**
5. Get your **Bucket ID** from the Buckets page

### Step 2: Edit the Test File

Open `test-simple.php` and update the configuration:

```php
$config = [
    'api_url' => 'http://localhost:8000/api', // Your API URL
    'access_key_id' => 'sk_xxxxx',            // Your access key
    'secret_access_key' => 'secret_xxxxx',    // Your secret key
    'bucket' => 'bucket-uuid-here',           // Your bucket UUID
    'allow_insecure_http' => true,            // Local development only
];
```

### Step 3: Run the Test

```bash
cd d:\store-doc\packages\s3-store-doc
set STORE_DOC_TEST_ALLOW_MUTATIONS=true
php test-simple.php
```

Without `STORE_DOC_TEST_ALLOW_MUTATIONS=true`, the live scripts perform only
read-only checks and skip the upload/delete portion.

## What the Test Does

The test script will:

1. ✅ Initialize the client
2. ✅ List all buckets
3. ✅ List objects in your bucket
4. ✅ Upload a test file
5. ✅ Check if the file exists
6. ✅ Download the file and verify content
7. ✅ Get file metadata
8. ✅ Generate a presigned URL
9. ✅ Delete the test file

## Expected Output

```
═══════════════════════════════════════════
  Store-Doc Package - Simple Test
═══════════════════════════════════════════

1. Initializing client...
   ✓ Client initialized

2. Testing bucket listing...
   ✓ Found 2 bucket(s)
     - my-bucket
     - test-bucket

3. Testing object listing...
   ✓ Found 5 object(s)
     - file1.pdf
     - image.jpg
     - document.docx

4. Testing file upload...
   ✓ File uploaded: test-1234567890.txt
   ✓ Object ID: abc123def456...

5. Testing file existence...
   ✓ File exists: YES

6. Testing file download...
   ✓ Content matches: YES

7. Testing metadata retrieval...
   ✓ Metadata retrieved
     - Size: 35 bytes
     - MIME: text/plain

8. Testing presigned URL...
   ✓ URL generated
     https://your-domain.com/api/storage/d/abc123...

9. Testing file deletion...
   ✓ File deleted

═══════════════════════════════════════════
  ✅ ALL TESTS PASSED!
  The package is working correctly!
═══════════════════════════════════════════
```

## Troubleshooting

### Error: "Missing API credentials"

Make sure you've edited `test-simple.php` and replaced the placeholder values with your actual credentials.

### Error: "Unauthorized" or "Invalid credentials"

- Verify your Access Key ID and Secret Access Key are correct
- Make sure the API key is active (not suspended)
- Check that the API URL is correct

### Error: "Bucket not found"

- Verify the bucket UUID is correct
- Make sure the bucket belongs to your account
- Check that the bucket is active

### Error: "Connection refused"

- Make sure your Store-Doc API is running
- Verify the API URL is correct
- Check if you need to use HTTPS instead of HTTP

### Error: "Class not found"

Run composer install first:
```bash
cd d:\store-doc
composer install
```

## Advanced Test

For a more comprehensive test that includes bucket and folder creation, use:

```bash
php test-package.php
```

This test requires environment variables to be set in your `.env` file:

```env
STORE_DOC_API_URL=http://localhost:8000/api
STORE_DOC_ACCESS_KEY_ID=your_access_key_id
STORE_DOC_SECRET_KEY=your_secret_key
STORE_DOC_BUCKET=your_bucket_uuid
STORE_DOC_ALLOW_INSECURE_HTTP=true
```

Use HTTPS and keep `STORE_DOC_ALLOW_INSECURE_HTTP` unset/false in production.
The live test scripts perform real storage operations; use a dedicated test bucket
and least-privilege credentials. Never commit credentials or print signed URLs.

## Manual Testing with Laravel

You can also test the package in a Laravel application:

```php
// In tinker or a controller
use Illuminate\Support\Facades\Storage;

// Upload
Storage::disk('s3-store-doc')->put('test.txt', 'Hello World');

// Download
$content = Storage::disk('s3-store-doc')->get('test.txt');
echo $content; // Should output: Hello World

// Delete
Storage::disk('s3-store-doc')->delete('test.txt');
```

Run Laravel tinker:
```bash
php artisan tinker
```

Then paste the code above.
