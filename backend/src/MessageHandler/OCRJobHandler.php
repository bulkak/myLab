<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Analysis;
use App\Entity\Metric;
use App\Message\OCRJob;
use App\Repository\AnalysisRepository;
use App\Repository\MetricAliasRepository;
use App\Repository\UserRepository;
use App\Service\OcrManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
class OCRJobHandler
{
    private OcrManager $ocrManager;
    private AnalysisRepository $analysisRepository;
    private UserRepository $userRepository;
    private MetricAliasRepository $aliasRepository;
    private EntityManagerInterface $entityManager;
    private ParameterBagInterface $params;
    private LoggerInterface $logger;

    public function __construct(
        OcrManager $ocrManager,
        AnalysisRepository $analysisRepository,
        UserRepository $userRepository,
        MetricAliasRepository $aliasRepository,
        EntityManagerInterface $entityManager,
        ParameterBagInterface $params,
        LoggerInterface $logger
    ) {
        $this->ocrManager = $ocrManager;
        $this->analysisRepository = $analysisRepository;
        $this->userRepository = $userRepository;
        $this->aliasRepository = $aliasRepository;
        $this->entityManager = $entityManager;
        $this->params = $params;
        $this->logger = $logger;
    }

    public function __invoke(OCRJob $job): void
    {
        $analysisId = $job->getAnalysisId();
        $filePath = $job->getFilePath();
        $userId = $job->getUserId();
        $model = $job->getModel();

        $this->logger->info("Starting OCR processing for analysis ID: {$analysisId}", [
            'model' => $model ?? 'default',
        ]);

        // Get analysis
        $analysis = $this->analysisRepository->find($analysisId);
        if (!$analysis) {
            throw new UnrecoverableMessageHandlingException("Analysis not found: {$analysisId}");
        }

        // Get user
        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new UnrecoverableMessageHandlingException("User not found: {$userId}");
        }

        try {
            // Update status to processing
            $analysis->setStatus(Analysis::STATUS_PROCESSING);
            $this->entityManager->flush();

            // Full path to file
            $fullPath = $this->params->get('upload_dir') . '/' . $filePath;
            
            if (!file_exists($fullPath)) {
                throw new UnrecoverableMessageHandlingException("File not found: {$fullPath}");
            }

            // Use the new OcrManager with model selection
            $this->logger->info("Processing file with OcrManager: {$filePath}", [
                'model' => $model ?? 'default',
            ]);
            
            $parsedData = $this->ocrManager->process($fullPath, $model, (string)$analysisId);
            
            // Save debug images paths if available
            $debugDir = $this->params->get('upload_dir') . '/debug';
            if (is_dir($debugDir)) {
                $debugImages = [];
                foreach (['png', 'jpg', 'jpeg', 'gif'] as $ext) {
                    $files = glob($debugDir . "/debug_{$analysisId}_page_*." . $ext);
                    if (is_array($files)) {
                        $debugImages = array_merge($debugImages, $files);
                    }
                }
                if (!empty($debugImages)) {
                    // Store only filenames (not full paths) for security
                    $debugFilenames = array_map('basename', $debugImages);
                    $analysis->setDebugImagesPaths(json_encode($debugFilenames));
                    // Clean up old debug files (keep only recent ones)
                    $this->cleanupOldDebugImages($debugDir);
                }
            }
            
            // Save raw OCR result for debugging (serialize the array)
            $analysis->setOcrRawText(json_encode($parsedData, JSON_UNESCAPED_UNICODE));

            // Extract analysis date if present
            if ($parsedData['analysisDate'] ?? null) {
                $analysis->setAnalysisDate($parsedData['analysisDate']);
            }

            // Extract analysis title if present
            if ($parsedData['title'] ?? null) {
                $analysis->setTitle($parsedData['title']);
            }

            // Create metrics
            foreach ($parsedData['metrics'] as $metricData) {
                $metric = new Metric();
                $metric->setAnalysis($analysis);
                $metric->setName($metricData['name']);
                $metric->setValue($metricData['value']);
                $metric->setUnit($metricData['unit'] ?? null);
                $metric->setReferenceMin($metricData['referenceMin'] ?? null);
                $metric->setReferenceMax($metricData['referenceMax'] ?? null);
                $metric->setIsAboveNormal($metricData['isAboveNormal'] ?? null);
                $metric->setIsBelowNormal($metricData['isBelowNormal'] ?? null);

                // Check for canonical name alias
                $canonicalName = $this->aliasRepository->findCanonicalName($user, $metricData['name']);
                if ($canonicalName) {
                    $metric->setCanonicalName($canonicalName);
                }

                $this->entityManager->persist($metric);
            }

            // Update status to completed
            $analysis->setStatus(Analysis::STATUS_COMPLETED);
            $analysis->setErrorMessage(null); // Clear any previous error
            
            $this->entityManager->flush();
            
            $this->logger->info("OCR processing completed for analysis ID: {$analysisId}, created " . count($parsedData['metrics']) . " metrics");

        } catch (\Exception $e) {
            // Update status to error and store error message
            $analysis->setStatus(Analysis::STATUS_ERROR);
            $analysis->setErrorMessage($e->getMessage());
            
            // Try to save debug images even on error
            $debugDir = $this->params->get('upload_dir') . '/debug';
            if (is_dir($debugDir)) {
                $debugImages = [];
                foreach (['png', 'jpg', 'jpeg', 'gif'] as $ext) {
                    $files = glob($debugDir . "/debug_{$analysisId}_page_*." . $ext);
                    if (is_array($files)) {
                        $debugImages = array_merge($debugImages, $files);
                    }
                }
                if (!empty($debugImages)) {
                    $debugFilenames = array_map('basename', $debugImages);
                    $analysis->setDebugImagesPaths(json_encode($debugFilenames));
                    $this->cleanupOldDebugImages($debugDir);
                }
            }
            
            $this->entityManager->flush();
            
            $this->logger->error("Error in OCR processing: {$e->getMessage()}", [
                'exception' => $e,
                'analysisId' => $analysisId,
                'model' => $model ?? 'default',
            ]);
            
            // For unrecoverable errors, don't retry - just mark as error
            if ($e instanceof UnrecoverableMessageHandlingException) {
                return;
            }
            
            // For other errors, allow retries by re-throwing
            throw $e;
        }
    }

    /**
     * Clean up debug images older than 1 hour.
     */
    private function cleanupOldDebugImages(string $debugDir): void
    {
        $oneHourAgo = time() - 3600;
        
        $files = [];
        foreach (['png', 'jpg', 'jpeg', 'gif'] as $ext) {
            $matched = glob($debugDir . '/debug_*_page_*.' . $ext);
            if (is_array($matched)) {
                $files = array_merge($files, $matched);
            }
        }
        
        if ($files === false) {
            return;
        }
        
        foreach ($files as $file) {
            if (filemtime($file) < $oneHourAgo) {
                @unlink($file);
            }
        }
    }
}
