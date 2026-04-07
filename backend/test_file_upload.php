<?php
// Create a simple test JPEG image using GD
$image = imagecreatetruecolor(100, 100);
$bgColor = imagecolorallocate($image, 255, 0, 0);  // Red background
imagefill($image, 0, 0, $bgColor);

// Add some text
$textColor = imagecolorallocate($image, 255, 255, 255);  // White text
imagestring($image, 2, 10, 10, "Test Image", $textColor);

// Save as JPEG
$filename = '/tmp/test_medical.jpg';
imagejpeg($image, $filename, 100);
imagedestroy($image);

echo "Test image created: $filename\n";
echo "File size: " . filesize($filename) . " bytes\n";

// Now simulate an upload using curl
// First, we need to get a session/login

echo "\n--- Attempting to test file upload ---\n";

// Create a simple test by making a POST request
$file = new CURLFile($filename, 'image/jpeg', 'test_medical.jpg');
$post = array('file' => $file);

$ch = curl_init('http://nginx/upload');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'X-Requested-With: XMLHttpRequest'
]);
curl_setopt($ch, CURLOPT_VERBOSE, true);

// Capture headers
$headerData = '';
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headerData) {
    $headerData .= $header;
    return strlen($header);
});

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response Length: " . strlen($response) . "\n";

if ($httpCode === 401) {
    echo "Got 401 - Not authenticated (expected without login)\n";
} elseif ($httpCode === 200 || $httpCode === 302) {
    echo "Got $httpCode - Upload attempt succeeded\n";
    echo "Response: " . substr($response, 0, 500) . "\n";
} else {
    echo "Got unexpected code: $httpCode\n";
    if ($error) {
        echo "Error: $error\n";
    }
    echo "Response: " . substr($response, 0, 500) . "\n";
}

unlink($filename);
