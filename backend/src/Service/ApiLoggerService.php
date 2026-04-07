<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Analysis;
use App\Entity\ApiLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ApiLoggerService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    public function log(
        string $provider,
        string $endpoint,
        ?array $requestData = null,
        ?array $responseData = null,
        ?int $statusCode = null,
        ?float $durationSeconds = null,
        ?int $analysisId = null
    ): void {
        try {
            $apiLog = new ApiLog();
            $apiLog->setProvider($provider);
            $apiLog->setEndpoint($endpoint);
            $apiLog->setRequestData($requestData);
            $apiLog->setResponseData($responseData);
            $apiLog->setStatusCode($statusCode);
            $apiLog->setDurationSeconds($durationSeconds);

            if ($analysisId) {
                $analysis = $this->entityManager->getRepository(Analysis::class)->find($analysisId);
                if ($analysis) {
                    $apiLog->setAnalysis($analysis);
                }
            }

            $this->entityManager->persist($apiLog);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // We don't want API logging failures to break the main application flow
            $this->logger->error("Failed to save API log: " . $e->getMessage(), [
                'provider' => $provider,
                'endpoint' => $endpoint,
            ]);
        }
    }
}
