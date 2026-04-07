<?php
// Create a temp image from the PDF
$file = '/var/www/uploads/user_1/user_1_69d2cff4d292e4.35751349.pdf';
$tempDir = '/tmp/pdf_ollama_test_' . uniqid();
mkdir($tempDir);

// Convert PDF to image
$cmd = sprintf('pdftoppm -png %s %s 2>&1', escapeshellarg($file), escapeshellarg($tempDir . '/page'));
exec($cmd, $output, $ret);

if ($ret !== 0) {
    echo "PDF conversion failed\n";
    exit(1);
}

$images = glob($tempDir . '/page-*.png');
if (empty($images)) {
    echo "No images generated\n";
    exit(1);
}

$imagePath = $images[0];
echo "Testing Ollama with image: " . basename($imagePath) . "\n";
echo "Image size: " . filesize($imagePath) . " bytes\n\n";

// Read and encode image
$imageData = file_get_contents($imagePath);
$base64 = base64_encode($imageData);

echo "Sending to Ollama...\n";

$payload = [
    'model' => 'moondream:1.8b',
    'prompt' => 'Analyze this medical lab test document image and extract ALL test results. Return ONLY valid JSON in this exact format: {"title": "Test Name", "analysisDate": "YYYY-MM-DD", "metrics": []}',
    'images' => [$base64],
    'stream' => false,
];

$ch = curl_init('http://ollama:11434/api/generate');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
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
} elseif ($httpCode === 200) {
    $data = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "✓ Got response from Ollama\n";
        echo "Response length: " . strlen($data['response'] ?? '') . " bytes\n";
        echo "First 500 chars:\n";
        echo substr($data['response'] ?? '', 0, 500) . "\n";
    } else {
        echo "✗ Failed to decode JSON: " . json_last_error_msg() . "\n";
        echo "Raw response (first 500 bytes):\n";
        echo substr($response, 0, 500) . "\n";
    }
} else {
    echo "✗ HTTP $httpCode\n";
    echo "Response: " . substr($response, 0, 500) . "\n";
}

// Cleanup
system("rm -rf " . escapeshellarg($tempDir));
