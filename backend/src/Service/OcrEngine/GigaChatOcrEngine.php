<?php

declare(strict_types=1);

namespace App\Service\OcrEngine;

use App\Service\Contract\OcrEngineInterface;
use App\Service\Contract\PromptBuilderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GigaChatOcrEngine implements OcrEngineInterface
{
    private const AUTH_URL = 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth';
    private const API_URL = 'https://gigachat.devices.sberbank.ru/api/v1';

    private array $supportedModels = [
        'GigaChat-Pro' => [
            'name' => 'GigaChat-Pro',
            'ram_gb' => 0,
            'best_for' => 'Продвинутая модель для сложных задач',
        ],
        'GigaChat-Max' => [
            'name' => 'GigaChat-Max',
            'ram_gb' => 0,
            'best_for' => 'Самая мощная модель GigaChat',
        ],
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private PromptBuilderInterface $promptBuilder,
        private \App\Service\ApiLoggerService $apiLogger,
        private string $authKey,
        private string $defaultModel = 'GigaChat-Max'
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
        
        if (empty($this->authKey)) {
            throw new \RuntimeException("GigaChat API key is not configured (GIGACHAT_AUTH_KEY is empty)");
        }

        $this->logger->info("GigaChat OCR starting with model: {$selectedModel}", [
            'image_count' => count($imagesBase64),
        ]);

        $startTime = microtime(true);
        $statusCode = null;
        $requestData = null;
        $responseData = null;

        try {
            $token = $this->getAccessToken($jobId);

            // GigaChat пока не очень хорошо ест массив картинок за раз, поэтому отправляем каждую картинку отдельно
            $allMetrics = [];
            $firstImageId = null;

            foreach ($imagesBase64 as $index => $base64Image) {
                // 1. Загружаем картинку в GigaChat
                $imageId = $this->uploadImage($base64Image, $token, $jobId);
                
                if ($index === 0) {
                    $firstImageId = $imageId;
                }

                // 2. Отправляем запрос на распознавание (CSV) для этой страницы
                $promptCsv = $this->promptBuilder->buildMedicalAnalysisPrompt($selectedModel);
                
                $this->logger->debug("Sending CSV request to GigaChat for page " . ($index + 1), [
                    'model' => $selectedModel,
                    'image_id' => $imageId,
                ]);

                $requestDataCsv = [
                    'model' => $selectedModel,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $promptCsv,
                            'attachments' => [
                                $imageId
                            ]
                        ]
                    ],
                    'temperature' => 0.0,
                    'top_p' => 0.1,
                    'max_tokens' => 8192,
                ];

                $requestData["csv_request_page_" . ($index + 1)] = $requestDataCsv;

                $responseCsv = $this->httpClient->request('POST', self::API_URL . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                    ],
                    'json' => $requestDataCsv,
                    'verify_peer' => false, 
                    'verify_host' => false,
                ]);

                $statusCode = $responseCsv->getStatusCode();
                
                if ($statusCode !== 200) {
                    $error = "GigaChat returned HTTP {$statusCode} on CSV step for page " . ($index + 1) . ": " . $responseCsv->getContent(false);
                    throw new \RuntimeException($error);
                }

                $responseDataCsv = $responseCsv->toArray();
                $csvContent = $responseDataCsv['choices'][0]['message']['content'] ?? '';
                
                $responseData["csv_response_page_" . ($index + 1)] = $responseDataCsv;

                // Парсим CSV в метрики и добавляем к общему списку
                $pageMetrics = $this->csvToMetrics($csvContent);
                $allMetrics = array_merge($allMetrics, $pageMetrics);
            }

            // 3. Отправляем запрос на дату (только для первой страницы)
            $promptDate = CompletionDateExtractor::PROMPT_DATE_RU;
            
            $this->logger->debug("Sending Date request to GigaChat", [
                'model' => $selectedModel,
                'image_id' => $firstImageId,
            ]);

            $requestDataDate = [
                'model' => $selectedModel,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $promptDate,
                        'attachments' => [
                            $firstImageId
                        ]
                    ]
                ],
                'temperature' => 0.0,
                'top_p' => 0.1,
                'max_tokens' => CompletionDateExtractor::DATE_STEP_MAX_TOKENS,
            ];

            $requestData['date_request'] = $requestDataDate;

            $responseDate = $this->httpClient->request('POST', self::API_URL . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'json' => $requestDataDate,
                'verify_peer' => false, 
                'verify_host' => false,
            ]);

            $statusCodeDate = $responseDate->getStatusCode();
            
            if ($statusCodeDate !== 200) {
                $this->logger->warning("GigaChat returned HTTP {$statusCodeDate} on Date step: " . $responseDate->getContent(false));
                $dateContent = null;
            } else {
                $responseDataDate = $responseDate->toArray();
                $responseData['date_response'] = $responseDataDate;
                $dateContent = CompletionDateExtractor::fromChatCompletionResponse($responseDataDate);
            }

            // 4. Формируем итоговый JSON
            $result = [
                'title' => 'Медицинский анализ',
                'analysisDate' => $dateContent,
                'metrics' => $allMetrics
            ];

            $jsonResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $this->logger->info("GigaChat OCR completed", [
                'model' => $selectedModel,
                'metrics_count' => count($allMetrics),
            ]);

            return $jsonResult;

        } catch (\Exception $e) {
            $this->logger->error("GigaChat OCR failed: {$e->getMessage()}", [
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
            $this->apiLogger->log(
                provider: 'gigachat',
                endpoint: '/chat/completions (2 steps)',
                requestData: $requestData,
                responseData: $responseData,
                statusCode: $statusCode,
                durationSeconds: $duration,
                analysisId: $jobId ? (int)$jobId : null
            );
        }
    }

    private function csvToMetrics(string $csvText): array
    {
        // Очищаем от возможных блоков кода (если модель вернула ```csv ... ```)
        $csvText = preg_replace('/```(?:csv)?\s*(.*?)\s*```/s', '$1', $csvText);
        
        $lines = explode("\n", $csvText);
        $metrics = [];
        
        // Пропускаем заголовок (первая строка)
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            // Разбираем CSV с разделителем "|"
            $cols = array_map('trim', explode('|', $line));
            
            // Нужно минимум 2 колонки (название и значение)
            if (count($cols) < 2) continue;
            
            $name = $cols[0];
            $value = $cols[1];
            
            // Пропускаем строки без значения
            if (empty($value) || $value === '-' || $value === '') continue;
            
            $unit = $cols[2] ?? null;
            $refMin = $cols[3] ?? null;
            $refMax = $cols[4] ?? null;
            
            // Очищаем null-значения
            if (empty($unit)) $unit = null;
            if (empty($refMin)) $refMin = null;
            if (empty($refMax)) $refMax = null;
            
            $metrics[] = [
                'name' => $name,
                'value' => $value,
                'unit' => $unit,
                'referenceMin' => $refMin,
                'referenceMax' => $refMax,
                'isAboveNormal' => null, // OcrManager/AnalysisParserService takes care of this if null
                'isBelowNormal' => null,
            ];
        }
        
        return $metrics;
    }

    private function getAccessToken(?string $jobId = null): string
    {
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $startTime = microtime(true);
        $requestData = ['scope' => 'GIGACHAT_API_PERS', 'RqUID' => $uuid];
        $responseData = null;
        $statusCode = null;

        try {
            $response = $this->httpClient->request('POST', self::AUTH_URL, [
                'headers' => [
                    'Authorization' => 'Basic ' . $this->authKey,
                    'RqUID' => $uuid,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'body' => ['scope' => 'GIGACHAT_API_PERS'],
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = $response->toArray(false);

            if ($statusCode !== 200) {
                throw new \RuntimeException("Auth failed: " . json_encode($responseData));
            }

            return $responseData['access_token'];
        } catch (\Exception $e) {
            if ($e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
                $statusCode = $e->getResponse()->getStatusCode();
                $responseData = ['error' => $e->getResponse()->getContent(false)];
            }
            throw $e;
        } finally {
            $this->apiLogger->log(
                provider: 'gigachat_auth',
                endpoint: '/api/v2/oauth',
                requestData: $requestData,
                responseData: $responseData,
                statusCode: $statusCode,
                durationSeconds: microtime(true) - $startTime,
                analysisId: $jobId ? (int)$jobId : null
            );
        }
    }

    private function uploadImage(string $base64, string $token, ?string $jobId = null): string
    {
        $binaryData = base64_decode($base64);
        
        $formFields = [
            'file' => new DataPart($binaryData, 'image.jpg', 'image/jpeg'),
            'purpose' => 'general',
        ];
        
        $formData = new FormDataPart($formFields);

        $startTime = microtime(true);
        $requestData = ['purpose' => 'general', 'file' => '[BASE64_IMAGE_DATA_REMOVED]'];
        $responseData = null;
        $statusCode = null;

        try {
            $response = $this->httpClient->request('POST', self::API_URL . '/files', [
                'headers' => array_merge(
                    ['Authorization' => 'Bearer ' . $token],
                    $formData->getPreparedHeaders()->toArray()
                ),
                'body' => $formData->bodyToIterable(),
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = $response->toArray(false);

            if ($statusCode !== 200) {
                throw new \RuntimeException("Upload failed: " . json_encode($responseData));
            }

            return $responseData['id'];
        } catch (\Exception $e) {
            if ($e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface) {
                $statusCode = $e->getResponse()->getStatusCode();
                $responseData = ['error' => $e->getResponse()->getContent(false)];
            }
            throw $e;
        } finally {
            $this->apiLogger->log(
                provider: 'gigachat_upload',
                endpoint: '/api/v1/files',
                requestData: $requestData,
                responseData: $responseData,
                statusCode: $statusCode,
                durationSeconds: microtime(true) - $startTime,
                analysisId: $jobId ? (int)$jobId : null
            );
        }
    }
}
