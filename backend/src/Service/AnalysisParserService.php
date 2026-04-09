<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class AnalysisParserService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Parse OCR result and extract structured data
     *
     * @return array{title: string|null, analysisDate: \DateTimeImmutable|null, metrics: array<int, array<string, mixed>>, notes: string|null}
     */
    public function parse(string $ocrResult): array
    {
        // Try to extract JSON from the response
        $json = $this->extractJson($ocrResult);
        
        if (!$json) {
            $this->logger->warning("No valid JSON found in OCR result, attempting fallback parsing");
            // Fallback: return empty structure
            return [
                'title' => null,
                'analysisDate' => null,
                'metrics' => [],
                'notes' => $ocrResult
            ];
        }

        // Parse JSON
        $data = json_decode($json, true);
        
        if (!$data) {
            $this->logger->error("Failed to parse JSON: " . json_last_error_msg());
            return [
                'title' => null,
                'analysisDate' => null,
                'metrics' => [],
                'notes' => $ocrResult
            ];
        }

        // Normalize the data
        $result = [
            'title' => $data['title'] ?? null,
            'analysisDate' => $this->parseDate($data['analysisDate'] ?? null),
            'metrics' => [],
            'notes' => $data['notes'] ?? null
        ];

        // Process metrics - be very defensive about format
        if (isset($data['metrics'])) {
            if (!is_array($data['metrics'])) {
                $this->logger->warning("Metrics is not an array: " . gettype($data['metrics']));
            } else {
                foreach ($data['metrics'] as $metricData) {
                    // Skip if metric is not an array (e.g., if Ollama returned invalid format like numbers)
                    if (!is_array($metricData)) {
                        $this->logger->debug("Skipping non-array metric: " . var_export($metricData, true));
                        continue;
                    }
                    
                    $metric = $this->normalizeMetric($metricData);
                    if ($metric) {
                        $result['metrics'][] = $metric;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Extract JSON from OCR response text
     */
    private function extractJson(string $text): ?string
    {
        // Look for JSON between code blocks
        if (preg_match('/```(?:json)?\s*({.*?})\s*```/s', $text, $matches)) {
            return $matches[1];
        }

        // Look for JSON object directly
        if (preg_match('/({.*})/s', $text, $matches)) {
            // Find the outermost braces
            $json = $matches[1];
            // Count braces to find valid JSON
            $open = 0;
            $start = strpos($text, '{');
            $end = false;
            
            for ($i = $start; $i < strlen($text); $i++) {
                if ($text[$i] === '{') $open++;
                if ($text[$i] === '}') $open--;
                if ($open === 0) {
                    $end = $i;
                    break;
                }
            }
            
            if ($end !== false) {
                return substr($text, $start, $end - $start + 1);
            }
        }

        return null;
    }

    /**
     * Normalize a metric from OCR data
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function normalizeMetric(array $data): ?array
    {
        // Validate required fields
        if (empty($data['name'])) {
            return null;
        }

        $metric = [
            'name' => trim($data['name']),
            'value' => $this->normalizeValue($data['value'] ?? ''),
            'unit' => !empty($data['unit']) ? trim($data['unit']) : null,
            'referenceMin' => $this->normalizeNumericValue($data['referenceMin'] ?? null),
            'referenceMax' => $this->normalizeNumericValue($data['referenceMax'] ?? null),
            'isAboveNormal' => $this->normalizeBoolean($data['isAboveNormal'] ?? null),
            'isBelowNormal' => $this->normalizeBoolean($data['isBelowNormal'] ?? null),
        ];

        // Detect H/L from value or name if boolean flags not set
        if ($metric['isAboveNormal'] === null && $metric['isBelowNormal'] === null) {
            $value = (string)($data['value'] ?? '');
            if (strpos($value, '↑') !== false || strpos($value, 'H') !== false || preg_match('/\s*↑\s*/', $value)) {
                $metric['isAboveNormal'] = true;
            }
            if (strpos($value, '↓') !== false || strpos($value, 'L') !== false || preg_match('/\s*↓\s*/', $value)) {
                $metric['isBelowNormal'] = true;
            }
        }

        return $metric;
    }

    /**
     * Normalize value to string
     *
     * @param mixed $value
     */
    private function normalizeValue($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        
        // Remove arrows and markers
        $cleaned = preg_replace('/[↑↓HL*]+/', '', (string)$value);
        $cleaned = trim($cleaned);
        
        return $cleaned;
    }

    /**
     * Normalize numeric value for database storage
     *
     * @param mixed $value
     */
    private function normalizeNumericValue($value): ?string
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }
        
        // Convert to string with proper decimal format
        $str = (string)$value;
        
        // Replace comma with dot for decimal
        $str = str_replace(',', '.', $str);
        
        // Remove any non-numeric characters except dot and minus
        $str = preg_replace('/[^\d.\-]/', '', $str);
        
        if ($str === '' || $str === '-' || $str === '.') {
            return null;
        }
        
        return $str;
    }

    /**
     * Normalize boolean value
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
            return in_array(strtolower($value), ['true', 'yes', '1', 'high', 'above', 'elevated']);
        }
        
        if (is_int($value)) {
            return $value !== 0;
        }
        
        return null;
    }

    /**
     * Parse date string to DateTimeImmutable
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
}
