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

        $fullPath = null;
        $uploadDir = $this->params->get('upload_dir');
        if (!is_string($uploadDir)) {
            throw new UnrecoverableMessageHandlingException("Invalid upload directory configuration");
        }

        try {
            // Update status to processing
            $analysis->setStatus(Analysis::STATUS_PROCESSING);
            $this->entityManager->flush();

            // Full path to file
            $fullPath = $uploadDir . '/' . $filePath;
            
            if (!file_exists($fullPath)) {
                throw new UnrecoverableMessageHandlingException("File not found: {$fullPath}");
            }

            // Use the new OcrManager with model selection
            $this->logger->info("Processing file with OcrManager: {$filePath}", [
                'model' => $model ?? 'default',
            ]);
            
            $parsedData = $this->ocrManager->process($fullPath, $model, (string)$analysisId);

            $previewPaths = $this->persistAnalysisDocumentImages($analysisId, $fullPath, $uploadDir);
            if ($previewPaths !== []) {
                $debugImagesJson = json_encode($previewPaths, JSON_UNESCAPED_UNICODE);
                if ($debugImagesJson !== false) {
                    $analysis->setDebugImagesPaths($debugImagesJson);
                }
            }

            // Save raw OCR result for debugging (serialize the array)
            $ocrRawText = json_encode($parsedData, JSON_UNESCAPED_UNICODE);
            if ($ocrRawText !== false) {
                $analysis->setOcrRawText($ocrRawText);
            }

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

            if ($fullPath !== null && is_file($fullPath)) {
                $previewPaths = $this->persistAnalysisDocumentImages($analysisId, $fullPath, $uploadDir);
                if ($previewPaths !== []) {
                    $debugImagesJson = json_encode($previewPaths, JSON_UNESCAPED_UNICODE);
                    if ($debugImagesJson !== false) {
                        $analysis->setDebugImagesPaths($debugImagesJson);
                    }
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
     * Копирует страницы документа в постоянное хранилище (document_previews/{id}/),
     * чтобы превью не пропадали после очистки временного каталога /debug.
     * Для загрузки сразу картинки (не PDF) копирует исходный файл как page_1.*.
     *
     * @return list<string> пути относительно upload_dir
     */
    private function persistAnalysisDocumentImages(int $analysisId, string $fullPath, string $uploadDir): array
    {
        $permDir = $uploadDir . '/document_previews/' . $analysisId;
        if (!is_dir($permDir) && !@mkdir($permDir, 0775, true) && !is_dir($permDir)) {
            $this->logger->error('Cannot create document_previews directory', ['dir' => $permDir]);

            return [];
        }

        $rels = [];
        $debugDir = $uploadDir . '/debug';
        foreach (['png', 'jpg', 'jpeg', 'gif', 'webp'] as $ext) {
            $pattern = $debugDir . "/debug_{$analysisId}_page_*." . $ext;
            foreach (glob($pattern) ?: [] as $src) {
                $base = basename($src);
                $dest = $permDir . '/' . $base;
                if (@copy($src, $dest)) {
                    $rels[] = 'document_previews/' . $analysisId . '/' . $base;
                    @unlink($src);
                }
            }
        }

        natsort($rels);
        $rels = array_values($rels);

        if ($rels !== []) {
            return $rels;
        }

        $mime = @mime_content_type($fullPath) ?: '';
        if (str_starts_with($mime, 'image/')) {
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION) ?: 'png');
            if (!preg_match('/^(png|jpe?g|gif|webp|bmp|tiff?)$/i', $ext)) {
                $ext = 'png';
            }
            $destName = 'page_1.' . $ext;
            $dest = $permDir . '/' . $destName;
            if (@copy($fullPath, $dest)) {
                return ['document_previews/' . $analysisId . '/' . $destName];
            }
        }

        return [];
    }
}
