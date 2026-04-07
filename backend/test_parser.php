<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Service\AnalysisParserService;
use Psr\Log\LoggerInterface;

// Create a simple logger
class SimpleLogger implements LoggerInterface {
    public function log($level, string|object $message, array $context = []): void {
        echo "[$level] $message\n";
        if (!empty($context)) {
            echo "Context: " . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
    public function emergency(string|object $message, array $context = []): void { $this->log('EMERGENCY', $message, $context); }
    public function alert(string|object $message, array $context = []): void { $this->log('ALERT', $message, $context); }
    public function critical(string|object $message, array $context = []): void { $this->log('CRITICAL', $message, $context); }
    public function error(string|object $message, array $context = []): void { $this->log('ERROR', $message, $context); }
    public function warning(string|object $message, array $context = []): void { $this->log('WARNING', $message, $context); }
    public function notice(string|object $message, array $context = []): void { $this->log('NOTICE', $message, $context); }
    public function info(string|object $message, array $context = []): void { $this->log('INFO', $message, $context); }
    public function debug(string|object $message, array $context = []): void { $this->log('DEBUG', $message, $context); }
}

$logger = new SimpleLogger();
$parser = new AnalysisParserService($logger);

// Test with the actual response from Ollama
$ocrResult = '{"title": "Test Name", "analysisDate": "2021-06-14", "metrics": [0.64, 0.78, 0.72, 0.86] }';

echo "=== Testing AnalysisParserService ===\n\n";
echo "Input: $ocrResult\n\n";

try {
    $parsed = $parser->parse($ocrResult);
    
    echo "=== Parsed Result ===\n";
    echo "Title: " . ($parsed['title'] ?? 'NULL') . "\n";
    echo "Date: " . ($parsed['analysisDate'] ? $parsed['analysisDate']->format('Y-m-d') : 'NULL') . "\n";
    echo "Metrics count: " . count($parsed['metrics']) . "\n";
    
    if (!empty($parsed['metrics'])) {
        echo "Metrics:\n";
        foreach ($parsed['metrics'] as $i => $m) {
            echo "  [$i] " . json_encode($m) . "\n";
        }
    } else {
        echo "No metrics parsed!\n";
    }
    
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
