<?php
// Test script to trigger OCR processing directly

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Entity\User;
use App\Entity\Analysis;
use App\Message\OCRJob;
use App\Service\OCRService;
use App\Service\AnalysisParserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

$kernel = new \App\Kernel($_ENV['APP_ENV'] ?? 'dev', $_ENV['APP_DEBUG'] ?? false);
$kernel->boot();
$container = $kernel->getContainer();

$logger = $container->get(LoggerInterface::class);
$entityManager = $container->get(EntityManagerInterface::class);
$ocrService = $container->get(OCRService::class);
$parserService = $container->get(AnalysisParserService::class);

echo "=== Testing OCR Pipeline ===\n\n";

// Create a test image
$imageData = imagecreatetruecolor(100, 100);
$red = imagecolorallocate($imageData, 255, 0, 0);
imagefill($imageData, 0, 0, $red);
imagestring($imageData, 2, 10, 10, "Hemoglobin: 140", imagecolorallocate($imageData, 255, 255, 255));
imagestring($imageData, 2, 10, 30, "WBC: 7.5", imagecolorallocate($imageData, 255, 255, 255));

$testImagePath = '/tmp/test_ocr_image.jpg';
imagejpeg($imageData, $testImagePath, 100);
imagedestroy($imageData);

echo "1. Test image created: $testImagePath\n";
echo "   File size: " . filesize($testImagePath) . " bytes\n\n";

try {
    echo "2. Testing OCRService::recognizeWithOllama()...\n";
    $result = $ocrService->recognizeWithOllama($testImagePath);
    echo "   ✓ Result received, length: " . strlen($result) . " bytes\n";
    echo "   Sample: " . substr($result, 0, 200) . "...\n\n";
    
    echo "3. Testing AnalysisParserService::parse()...\n";
    $parsed = $parserService->parse($result);
    echo "   ✓ Parsed successfully\n";
    echo "   Title: " . ($parsed['title'] ?? 'N/A') . "\n";
    echo "   Metrics count: " . count($parsed['metrics']) . "\n";
    if (!empty($parsed['metrics'])) {
        echo "   First metric: " . $parsed['metrics'][0]['name'] . "\n";
    }
    echo "\n";
    
    echo "4. All tests passed! ✓\n";
    
} catch (\Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n";
    echo "\nFull exception:\n";
    echo $e->getTraceAsString() . "\n";
}

unlink($testImagePath);
$kernel->shutdown();
