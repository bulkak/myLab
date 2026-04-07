<?php
$file = '/var/www/uploads/user_1/user_1_69d2cff4d292e4.35751349.pdf';
$tempDir = '/tmp/pdf_llava_test_' . uniqid();
mkdir($tempDir);

$cmd = sprintf('pdftoppm -png %s %s 2>&1', escapeshellarg($file), escapeshellarg($tempDir . '/page'));
exec($cmd, $output, $ret);

if ($ret !== 0 || empty(glob($tempDir . '/page-*.png'))) {
    echo "Failed to convert PDF\n";
    exit(1);
}

$imagePath = glob($tempDir . '/page-*.png')[0];
$imageData = file_get_contents($imagePath);
$base64 = base64_encode($imageData);

echo "Testing llava:13b with medical prompt...\n\n";

$prompt = <<<'PROMPT'
You are a medical document OCR specialist. Analyze this medical lab test image and extract ALL visible test results in JSON format.

Return ONLY valid JSON with this exact structure (no markdown, no explanation):
{
  "title": "Test name",
  "analysisDate": "YYYY-MM-DD",
  "metrics": [
    {
      "name": "Metric name",
      "value": "value",
      "unit": "unit",
      "referenceMin": "min",
      "referenceMax": "max"
    }
  ]
}

Extract ALL metrics from the document precisely.
PROMPT;

$payload = [
    'model' => 'llava:13b',
    'prompt' => $prompt,
    'images' => [$base64],
    'stream' => false,
];

echo "Sending request to Ollama llava:13b...\n";
$start = time();

$ch = curl_init('http://ollama:11434/api/generate');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$elapsed = time() - $start;
curl_close($ch);

echo "Response time: ${elapsed}s\n";
echo "HTTP Code: $httpCode\n";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $result = $data['response'] ?? '';
    
    echo "\nFull response:\n";
    echo $result . "\n\n";
    
    // Try to extract JSON
    $trimmed = trim($result);
    if (strpos($trimmed, '```') !== false) {
        preg_match('/```(?:json)?\s*({.*})\s*```/s', $trimmed, $matches);
        $trimmed = $matches[1] ?? $trimmed;
    }
    
    $parsed = @json_decode($trimmed, true);
    if ($parsed && is_array($parsed)) {
        echo "✓ Valid JSON\n";
        echo "Title: " . ($parsed['title'] ?? 'N/A') . "\n";
        echo "Date: " . ($parsed['analysisDate'] ?? 'N/A') . "\n";
        echo "Metrics: " . count($parsed['metrics'] ?? []) . "\n";
        if (!empty($parsed['metrics']) && is_array($parsed['metrics'][0] ?? null)) {
            echo "First metric is array: ✓\n";
            echo "First metric: " . json_encode($parsed['metrics'][0]) . "\n";
        }
    } else {
        echo "✗ Invalid JSON\n";
    }
} else {
    echo "Error: $httpCode\n";
    echo "Response: " . substr($response, 0, 500) . "\n";
}

system("rm -rf " . escapeshellarg($tempDir));
