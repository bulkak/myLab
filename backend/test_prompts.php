<?php
// Test improved prompt with Ollama
$file = '/var/www/uploads/user_1/user_1_69d2cff4d292e4.35751349.pdf';
$tempDir = '/tmp/pdf_prompt_test_' . uniqid();
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

echo "=== Testing Improved Prompts ===\n\n";

$prompts = [
    'simple' => <<<'PROMPT'
Analyze this medical lab test image. Extract test results as JSON.
PROMPT,
    
    'detailed' => <<<'PROMPT'
You are a medical document OCR specialist. Analyze the provided medical lab test image and extract ALL test results.

Return ONLY valid JSON in this exact format, with NO markdown code blocks:
{
  "title": "Name of the test",
  "analysisDate": "YYYY-MM-DD",
  "metrics": [
    {
      "name": "Metric Name",
      "value": "123.45",
      "unit": "unit",
      "referenceMin": "100",
      "referenceMax": "200"
    }
  ]
}

Be precise and extract ALL visible metrics.
PROMPT,

    'strict_json' => <<<'PROMPT'
Extract medical lab test results from this image. Output ONLY this JSON structure with NO extra text:
{"title":"test name","analysisDate":"YYYY-MM-DD","metrics":[{"name":"name","value":"value","unit":"unit"}]}
PROMPT,
];

foreach ($prompts as $name => $prompt) {
    echo "--- Testing '$name' prompt ---\n";
    
    $payload = [
        'model' => 'moondream:1.8b',
        'prompt' => $prompt,
        'images' => [$base64],
        'stream' => false,
    ];
    
    $ch = curl_init('http://ollama:11434/api/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $result = $data['response'] ?? '';
        
        echo "Response (first 300 chars): " . substr($result, 0, 300) . "\n";
        
        // Try to parse as JSON
        $trimmed = trim($result);
        // Remove markdown if present
        if (strpos($trimmed, '```') !== false) {
            preg_match('/```(?:json)?\s*({.*})\s*```/s', $trimmed, $matches);
            $trimmed = $matches[1] ?? $trimmed;
        }
        
        $parsed = @json_decode($trimmed, true);
        if ($parsed && is_array($parsed)) {
            echo "✓ Valid JSON\n";
            echo "  Metrics type: " . (isset($parsed['metrics'][0]) ? gettype($parsed['metrics'][0]) : 'empty') . "\n";
        } else {
            echo "✗ Invalid JSON\n";
        }
    } else {
        echo "✗ HTTP $httpCode\n";
    }
    
    echo "\n";
}

system("rm -rf " . escapeshellarg($tempDir));
