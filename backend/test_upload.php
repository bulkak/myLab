<?php
require_once '/var/www/html/config/bootstrap.php';
require_once '/var/www/html/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Kernel;

// Create a simple test image (1x1 transparent PNG)
$testImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

// Write to temp file
$tempFile = tempnam(sys_get_temp_dir(), 'test_');
file_put_contents($tempFile, $testImageData);

// Move to a location where we can access it
copy($tempFile, '/tmp/test_image.png');

echo "Test image created at: /tmp/test_image.png\n";
echo "File size: " . filesize('/tmp/test_image.png') . " bytes\n";
echo "File exists: " . (file_exists('/tmp/test_image.png') ? 'yes' : 'no') . "\n";

// Now create an UploadedFile instance
$uploadedFile = new UploadedFile(
    '/tmp/test_image.png',
    'test_image.png',
    'image/png',
    null,
    true // Move uploaded file
);

echo "UploadedFile created\n";
echo "Uploaded file size: " . $uploadedFile->getSize() . "\n";
echo "Uploaded file MIME: " . $uploadedFile->getMimeType() . "\n";

unlink($tempFile);
unlink('/tmp/test_image.png');
echo "Cleanup done\n";
