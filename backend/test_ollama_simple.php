<?php
echo "Testing Ollama connection...\n";

$ch = curl_init('http://ollama:11434/api/generate');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'moondream:1.8b',
    'prompt' => 'Hello. What is your name?',
    'stream' => false,
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "Error: $error\n";
} else {
    $data = json_decode($response, true);
    if ($data) {
        echo "Response length: " . strlen($data['response'] ?? '') . "\n";
        echo "Response (first 300 chars): " . substr($data['response'] ?? '', 0, 300) . "\n";
    } else {
        echo "Response: " . substr($response, 0, 300) . "\n";
    }
}
