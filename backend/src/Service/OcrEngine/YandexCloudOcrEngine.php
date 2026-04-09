<?php

declare(strict_types=1);

namespace App\Service\OcrEngine;

use App\Service\Contract\OcrEngineInterface;
use App\Service\Contract\PromptBuilderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YandexCloudOcrEngine implements OcrEngineInterface
{
    /** @var array<string, array{name: string, ram_gb: int, best_for: string}> */
    private array $supportedModels = [
        'qwen3.5-35b-a3b-fp8/latest' => [
            'name' => 'qwen3.5-35b-a3b-fp8/latest',
            'ram_gb' => 0,
            'best_for' => 'Модель Qwen 3.5 на Yandex Cloud',
        ],
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private PromptBuilderInterface $promptBuilder,
        private \App\Service\ApiLoggerService $apiLogger,
        private string $apiKey,
        private string $folderId,
        private string $apiUrl = 'https://ai.api.cloud.yandex.net/v1/chat/completions',
        private string $defaultModel = 'qwen3.5-35b-a3b-fp8/latest'
    ) {}

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, [
            'image/png',
            'image/jpeg',
            'image/jpg',
            'image/webp',
            'image/gif',
        ], true);
    }

    public function supportsModel(string $model): bool
    {
        return isset($this->supportedModels[$model]);
    }

    public function getAvailableModels(): array
    {
        return array_keys($this->supportedModels);
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    public function getModelInfo(string $model): ?array
    {
        return $this->supportedModels[$model] ?? null;
    }

    public function recognize(array $imagesBase64, ?string $model = null, ?string $jobId = null): string
    {
        $selectedModel = $model ?? $this->defaultModel;
        
        if (empty($this->apiKey) || empty($this->folderId)) {
            throw new \RuntimeException("Yandex Cloud API key or Folder ID is not configured");
        }

        $this->logger->info("Yandex Cloud OCR starting with model: {$selectedModel}", [
            'image_count' => count($imagesBase64),
        ]);

        $startTime = microtime(true);
        $statusCode = null;
        $requestData = null;
        $responseData = null;

        try {
            // 1. Отправляем запрос на распознавание (CSV)
            $promptCsv = $this->promptBuilder->buildMedicalAnalysisPrompt($selectedModel);
            
            $messagesCsv = [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $promptCsv
                        ]
                    ]
                ]
            ];

            // Add all images to the request
            foreach ($imagesBase64 as $base64Image) {
                $mimeType = 'image/jpeg';
                if (str_starts_with($base64Image, 'iVBORw0KGgo')) {
                    $mimeType = 'image/png';
                } elseif (str_starts_with($base64Image, 'UklGR')) {
                    $mimeType = 'image/webp';
                } elseif (str_starts_with($base64Image, 'R0lGOD')) {
                    $mimeType = 'image/gif';
                }

                $messagesCsv[0]['content'][] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$mimeType};base64,{$base64Image}"
                    ]
                ];
            }

            // Формируем URI модели в формате Yandex Cloud
            $fullModelName = "gpt://{$this->folderId}/{$selectedModel}";

            $requestDataCsv = [
                'model' => $fullModelName,
                'messages' => $messagesCsv,
                'temperature' => 0.3,
                'max_tokens' => 32768,
            ];

            $optionsCsv = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'x-folder-id' => $this->folderId,
                ],
                'json' => $requestDataCsv,
            ];

            $requestData = ['csv_request' => $requestDataCsv];

            $responseCsv = $this->httpClient->request('POST', $this->apiUrl, $optionsCsv);

            $statusCode = $responseCsv->getStatusCode();
            
            if ($statusCode !== 200) {
                $error = "Yandex Cloud returned HTTP {$statusCode} on CSV step: " . $responseCsv->getContent(false);
                throw new \RuntimeException($error);
            }

            $responseDataCsv = $responseCsv->toArray();
            $csvContent = $responseDataCsv['choices'][0]['message']['content'] ?? '';
            
            $responseData = ['csv_response' => $responseDataCsv];

            // Парсим CSV в метрики
            $metrics = $this->csvToMetrics($csvContent);

            // 2. Отправляем запрос на дату
            $promptDate = CompletionDateExtractor::PROMPT_DATE_RU;
            
            $messagesDate = [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $promptDate
                        ]
                    ]
                ]
            ];

            foreach ($imagesBase64 as $base64Image) {
                $mimeType = 'image/jpeg';
                if (str_starts_with($base64Image, 'iVBORw0KGgo')) {
                    $mimeType = 'image/png';
                } elseif (str_starts_with($base64Image, 'UklGR')) {
                    $mimeType = 'image/webp';
                } elseif (str_starts_with($base64Image, 'R0lGOD')) {
                    $mimeType = 'image/gif';
                }

                $messagesDate[0]['content'][] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$mimeType};base64,{$base64Image}"
                    ]
                ];
            }

            $requestDataDate = [
                'model' => $fullModelName,
                'messages' => $messagesDate,
                'temperature' => 0.0,
                'max_tokens' => CompletionDateExtractor::DATE_STEP_MAX_TOKENS,
                'reasoning_effort' => 'none',
            ];

            $optionsDate = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'x-folder-id' => $this->folderId,
                ],
                'json' => $requestDataDate,
            ];

            $requestData['date_request'] = $requestDataDate;

            $responseDate = $this->httpClient->request('POST', $this->apiUrl, $optionsDate);

            $statusCodeDate = $responseDate->getStatusCode();
            
            if ($statusCodeDate !== 200) {
                $this->logger->warning("Yandex Cloud returned HTTP {$statusCodeDate} on Date step: " . $responseDate->getContent(false));
                $dateContent = null;
            } else {
                $responseDataDate = $responseDate->toArray();
                $responseData['date_response'] = $responseDataDate;
                $dateContent = CompletionDateExtractor::fromChatCompletionResponse($responseDataDate);
            }

            // 3. Формируем итоговый JSON
            $result = [
                'title' => 'Медицинский анализ',
                'analysisDate' => $dateContent,
                'metrics' => $metrics
            ];

            $jsonResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $this->logger->info("Yandex Cloud OCR completed", [
                'model' => $selectedModel,
                'metrics_count' => count($metrics),
            ]);

            return $jsonResult;

        } catch (\Exception $e) {
            $this->logger->error("Yandex Cloud OCR failed: {$e->getMessage()}", [
                'model' => $selectedModel,
            ]);
            
            if ($e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
                $statusCode = $e->getResponse()->getStatusCode();
                $responseData = ['error' => $e->getResponse()->getContent(false)];
            } else {
                if ($responseData === null) {
                    $responseData = ['error' => $e->getMessage()];
                } else {
                    $responseData['error'] = $e->getMessage();
                }
            }
            
            throw new \RuntimeException("OCR processing failed: {$e->getMessage()}", 0, $e);
        } finally {
            $duration = microtime(true) - $startTime;
            
            // Hide base64 images from logs
            if (isset($requestData['csv_request']['messages'][0]['content'])) {
                foreach ($requestData['csv_request']['messages'][0]['content'] as &$contentItem) {
                    if ($contentItem['type'] === 'image_url') {
                        $contentItem['image_url']['url'] = '[BASE64_IMAGE_DATA_REMOVED]';
                    }
                }
            }
            if (isset($requestData['date_request']['messages'][0]['content'])) {
                foreach ($requestData['date_request']['messages'][0]['content'] as &$contentItem) {
                    if ($contentItem['type'] === 'image_url') {
                        $contentItem['image_url']['url'] = '[BASE64_IMAGE_DATA_REMOVED]';
                    }
                }
            }

            $this->apiLogger->log(
                provider: 'yandex_cloud',
                endpoint: '/chat/completions (2 steps)',
                requestData: $requestData,
                responseData: $responseData,
                statusCode: $statusCode,
                durationSeconds: $duration,
                analysisId: $jobId ? (int)$jobId : null
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function csvToMetrics(string $csvText): array
    {
        $csvText = preg_replace('/```(?:csv)?\s*(.*?)\s*```/s', '$1', $csvText);
        
        $lines = explode("\n", $csvText);
        $metrics = [];
        
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            $cols = array_map('trim', explode('|', $line));
            
            if (count($cols) < 2) continue;
            
            $name = $cols[0];
            $value = $cols[1];
            
            if (empty($value) || $value === '-') continue;
            
            $unit = $cols[2] ?? null;
            $refMin = $cols[3] ?? null;
            $refMax = $cols[4] ?? null;
            
            if (empty($unit)) $unit = null;
            if (empty($refMin)) $refMin = null;
            if (empty($refMax)) $refMax = null;
            
            $metrics[] = [
                'name' => $name,
                'value' => $value,
                'unit' => $unit,
                'referenceMin' => $refMin,
                'referenceMax' => $refMax,
                'isAboveNormal' => null,
                'isBelowNormal' => null,
            ];
        }
        
        return $metrics;
    }
}
