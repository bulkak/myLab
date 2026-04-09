<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Contract\DocumentConverterInterface;
use App\Service\Contract\OcrEngineInterface;
use Psr\Log\LoggerInterface;

/**
 * OCR Manager - Facade that orchestrates document processing.
 *
 * This class coordinates:
 * - Document conversion (PDF -> images)
 * - OCR engine selection
 * - Model-specific processing
 * - Result formatting
 *
 * It's the main entry point for all OCR operations in the application.
 */
class OcrManager
{
    /** @var iterable<OcrEngineInterface> */
    private iterable $ocrEngines;
    private DocumentConverterInterface $pdfConverter;
    private LoggerInterface $logger;
    private string $uploadDir;
    private string $defaultModel;

    /**
     * Supported image MIME types that don't need conversion.
     *
     * @var array<int, string>
     */
    private array $imageMimeTypes = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/webp',
        'image/gif',
    ];

    /**
     * @param iterable<OcrEngineInterface> $ocrEngines
     */
    public function __construct(
        iterable $ocrEngines,
        DocumentConverterInterface $pdfConverter,
        LoggerInterface $logger,
        string $uploadDir = '/var/www/uploads',
        string $defaultModel = 'qwen3.5-35b-a3b-fp8/latest'
    ) {
        $this->ocrEngines = $ocrEngines;
        $this->pdfConverter = $pdfConverter;
        $this->logger = $logger;
        $this->uploadDir = $uploadDir;
        $this->defaultModel = $defaultModel;
    }

    /**
     * Get the appropriate OCR engine for the model.
     */
    private function getEngineForModel(?string $model): OcrEngineInterface
    {
        $model = $model ?? $this->defaultModel;

        foreach ($this->ocrEngines as $engine) {
            if ($engine->supportsModel($model)) {
                return $engine;
            }
        }

        throw new \RuntimeException("No OCR engine found supporting model: {$model}");
    }

    /**
     * Process a file with OCR.
     *
     * This is the main entry point. It:
     * 1. Detects file type (PDF or image)
     * 2. Converts PDF to images if needed
     * 3. Calls the OCR engine with specified model
     * 4. Returns the structured result
     *
     * @param string $filePath Path to the file to process
     * @param string|null $model Specific OCR model to use, or null for default
     * @return array<string, mixed> Parsed OCR result with metrics
     * @throws \RuntimeException If processing fails
     */
    public function process(string $filePath, ?string $model = null, ?string $jobId = null): array
    {
        $this->logger->info("Starting OCR processing", [
            'file' => $filePath,
            'model' => $model ?? 'default',
            'jobId' => $jobId,
        ]);

        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        // Detect MIME type
        $mimeType = $this->detectMimeType($filePath);
        $this->logger->debug("Detected MIME type: {$mimeType}");

        // Get images for OCR
        $images = $this->getImagesForOcr($filePath, $mimeType, $jobId);

        // Note: Direct text extraction disabled - all PDFs go through OCR
        // for consistent structured data extraction
        if ($mimeType === 'application/pdf') {
            $this->logger->debug("PDF detected, will convert to images for OCR");
        }

        // Perform OCR on images using the appropriate engine
        $engine = $this->getEngineForModel($model);
        $ocrResult = $engine->recognize($images, $model, $jobId);

        // Parse and validate the result
        $parsed = $this->parseOcrResult($ocrResult);

        $this->logger->info("OCR processing complete", [
            'metrics_count' => count($parsed['metrics']),
        ]);

        return $parsed;
    }

    /**
     * Get list of available OCR models across all engines.
     *
     * @return array<array{name: string, ram_gb: float, best_for: string}>
     */
    public function getAvailableModels(): array
    {
        $result = [];

        foreach ($this->ocrEngines as $engine) {
            $models = $engine->getAvailableModels();
            foreach ($models as $model) {
                $info = $engine->getModelInfo($model);
                if ($info) {
                    $result[] = $info;
                }
            }
        }

        return $result;
    }

    /**
     * Get the default model name.
     */
    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * Check if a specific model is supported by any engine.
     */
    public function supportsModel(string $model): bool
    {
        foreach ($this->ocrEngines as $engine) {
            if ($engine->supportsModel($model)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get images for OCR processing.
     *
     * For PDFs: converts to images.
     * For images: returns as-is.
     *
     * @return array<string> Array of base64-encoded images
     */
    private function getImagesForOcr(string $filePath, string $mimeType, ?string $jobId = null): array
    {
        // PDF handling
        if ($mimeType === 'application/pdf' || $this->pdfConverter->supports($filePath)) {
            $this->logger->info("Converting PDF to images");
            
            // Create debug directory if doesn't exist
            $debugDir = $this->uploadDir . '/debug';
            if (!is_dir($debugDir)) {
                @mkdir($debugDir, 0777, true);
            }
            
            return $this->pdfConverter->convertToImages($filePath, $debugDir, $jobId);
        }

        // Image handling
        if (in_array($mimeType, $this->imageMimeTypes, true)) {
            return [$this->imageToBase64($filePath)];
        }

        throw new \RuntimeException("Unsupported file type: {$mimeType}");
    }

    /**
     * Detect MIME type of a file.
     */
    private function detectMimeType(string $filePath): string
    {
        // Try finfo first
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            return $mime;
        }

        // Fallback to extension
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * Convert an image file to base64.
     */
    private function imageToBase64(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read image: {$filePath}");
        }
        return base64_encode($content);
    }

    /**
     * Parse OCR result and extract JSON data.
     *
     * @return array{title: string|null, analysisDate: \DateTimeImmutable|null, metrics: array<int, array<string, mixed>>, notes: string|null}
     */
    private function parseOcrResult(string $ocrResult): array
    {
        // Try to extract JSON
        $json = $this->extractJson($ocrResult);

        if (!$json) {
            $this->logger->warning("No valid JSON found in OCR result");
            return [
                'title' => null,
                'analysisDate' => null,
                'metrics' => [],
                'notes' => $ocrResult,
            ];
        }

        $data = json_decode($json, true);
        if (!$data) {
            $this->logger->error("Failed to parse JSON: " . json_last_error_msg());
            return [
                'title' => null,
                'analysisDate' => null,
                'metrics' => [],
                'notes' => $ocrResult,
            ];
        }

        return [
            'title' => $data['title'] ?? null,
            'analysisDate' => $this->parseDate($data['analysisDate'] ?? null),
            'metrics' => $this->normalizeMetrics($data['metrics'] ?? []),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * Extract JSON from text.
     */
    private function extractJson(string $text): ?string
    {
        // Look for JSON between code blocks
        if (preg_match('/```(?:json)?\s*({.*?})\s*```/s', $text, $matches)) {
            return $matches[1];
        }

        // Look for JSON object directly
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        // Find matching closing brace
        $open = 0;
        $end = false;

        for ($i = $start; $i < strlen($text); $i++) {
            if ($text[$i] === '{') $open++;
            if ($text[$i] === '}') $open--;
            if ($open === 0) {
                $end = $i;
                break;
            }
        }

        if ($end === false) {
            return null;
        }

        return substr($text, $start, $end - $start + 1);
    }

    /**
     * Parse date string to DateTimeImmutable.
     */
    private function parseDate(?string $dateString): ?\DateTimeImmutable
    {
        if (!$dateString) {
            return null;
        }

        $formats = [
            'Y-m-d',
            'Y-m-d H:i:s',
            'd.m.Y',
            'd/m/Y',
            'd.m.Y H:i',
            'j.n.Y',
            'j/n/Y',
        ];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $dateString);
            if ($date !== false) {
                return $date;
            }
        }

        // Try strtotime as fallback
        $timestamp = strtotime($dateString);
        if ($timestamp !== false) {
            return new \DateTimeImmutable('@' . $timestamp);
        }

        return null;
    }

    /**
     * Normalize metrics array.
     *
     * @param array<int, mixed> $metrics
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMetrics(array $metrics): array
    {
        $result = [];

        foreach ($metrics as $metric) {
            if (!is_array($metric)) {
                $this->logger->debug("Skipping non-array metric");
                continue;
            }

            if (empty($metric['name'])) {
                continue;
            }

            $result[] = [
                'name' => trim($metric['name']),
                'value' => $this->normalizeValue($metric['value'] ?? ''),
                'unit' => !empty($metric['unit']) ? trim($metric['unit']) : null,
                'referenceMin' => $this->normalizeNumericValue($metric['referenceMin'] ?? null),
                'referenceMax' => $this->normalizeNumericValue($metric['referenceMax'] ?? null),
                'isAboveNormal' => $this->normalizeBoolean($metric['isAboveNormal'] ?? null),
                'isBelowNormal' => $this->normalizeBoolean($metric['isBelowNormal'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * Normalize value to string.
     *
     * @param mixed $value
     */
    private function normalizeValue($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $cleaned = preg_replace('/[↑↓HL*]+/', '', (string)$value);
        return trim($cleaned);
    }

    /**
     * Normalize numeric value.
     *
     * @param mixed $value
     */
    private function normalizeNumericValue($value): ?string
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }

        $str = (string)$value;
        $str = str_replace(',', '.', $str);
        $str = preg_replace('/[^\d.\-]/', '', $str);

        if ($str === '' || $str === '-' || $str === '.') {
            return null;
        }

        return $str;
    }

    /**
     * Normalize boolean value.
     *
     * @param mixed $value
     */
    private function normalizeBoolean($value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', 'yes', '1', 'high', 'above', 'elevated'], true);
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        return null;
    }
}
