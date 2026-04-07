<?php
// Test Ollama connectivity
$ch = curl_init('http://ollama:11434/api/tags');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "CURL Error: $error\n";
} else {
    echo "Response: " . substr($response, 0, 500) . "\n";
}

// Test with a simple image request
echo "\n--- Testing OCR Request ---\n";

$testImagePath = '/tmp/test_image.jpg';
if (file_exists($testImagePath)) {
    $imageData = file_get_contents($testImagePath);
    $base64 = base64_encode($imageData);
} else {
    // Create a simple 1x1 pixel image
    $base64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAhEAACAQMEAwEBAAAAAAAAAAABAgADBBESITFBUQVhcYGR/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAYEQEBAQEBAAAAAAAAAAAAAAAAAQEx/9oADAMBAAIRAxEAPwCdyuZyOZrIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA//Z';
}

$payload = [
    'model' => 'moondream:1.8b',
    'prompt' => 'What is in this image?',
    'images' => [$base64],
    'stream' => false,
];

$ch = curl_init('http://ollama:11434/api/generate');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "CURL Error: $error\n";
} else {
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "JSON Decode Error: " . json_last_error_msg() . "\n";
        echo "Raw Response: " . substr($response, 0, 1000) . "\n";
    } else {
        echo "Response received, length: " . strlen($response) . "\n";
        if (isset($data['response'])) {
            echo "OCR Result: " . substr($data['response'], 0, 200) . "\n";
        }
    }
}
