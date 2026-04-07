<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Analysis;
use App\Entity\Metric;
use App\Message\OCRJob;
use App\Repository\AnalysisRepository;
use App\Repository\MetricAliasRepository;
use App\Repository\UserRepository;
use App\Service\AuthSessionService;
use App\Service\OcrManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

use Psr\Log\LoggerInterface;

#[Route('/analysis')]
class AnalysisController extends AbstractController
{
    private AuthSessionService $sessionService;
    private UserRepository $userRepository;
    private AnalysisRepository $analysisRepository;
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $messageBus;
    private OcrManager $ocrManager;
    private LoggerInterface $logger;
    private MetricAliasRepository $metricAliasRepository;

    /**
     * Available OCR models for reprocessing.
     *
     * @var array<array{name: string, label: string, ram_gb: float, description: string}>
     */
    private array $availableModels = [
        [
            'name' => 'GigaChat-Pro',
            'label' => 'GigaChat Pro',
            'ram_gb' => 0,
            'description' => 'Продвинутая модель для сложных задач',
        ],
        [
            'name' => 'GigaChat-Max',
            'label' => 'GigaChat Max',
            'ram_gb' => 0,
            'description' => 'Самая мощная модель GigaChat',
        ],
        [
            'name' => 'gpt-4o',
            'label' => 'GPT-4o',
            'ram_gb' => 0,
            'description' => 'Быстрая и точная модель от OpenAI',
        ],
        [
            'name' => 'qwen3.5-35b-a3b-fp8/latest',
            'label' => 'Yandex Cloud Qwen 3.5',
            'ram_gb' => 0,
            'description' => 'Модель Qwen 3.5 на Yandex Cloud',
        ],
    ];

    public function __construct(
        AuthSessionService $sessionService,
        UserRepository $userRepository,
        AnalysisRepository $analysisRepository,
        EntityManagerInterface $entityManager,
        MessageBusInterface $messageBus,
        OcrManager $ocrManager,
        LoggerInterface $logger,
        MetricAliasRepository $metricAliasRepository
    ) {
        $this->sessionService = $sessionService;
        $this->userRepository = $userRepository;
        $this->analysisRepository = $analysisRepository;
        $this->entityManager = $entityManager;
        $this->messageBus = $messageBus;
        $this->ocrManager = $ocrManager;
        $this->logger = $logger;
        $this->metricAliasRepository = $metricAliasRepository;
    }

    /**
     * Get current user
     */
    private function getCurrentUser()
    {
        $userId = $this->sessionService->getUserId();
        if (!$userId) {
            return null;
        }
        return $this->userRepository->find($userId);
    }

    /**
     * Confirm an analysis to save it to history.
     */
    #[Route('/{id}/confirm', name: 'app_analysis_confirm', methods: ['POST'])]
    public function confirm(int $id, Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('auth_login');
        }

        $analysis = $this->analysisRepository->findOneByIdAndUser($id, $user);
        if (!$analysis) {
            throw $this->createNotFoundException('Анализ не найден');
        }

        // Process metric names if submitted
        $metricsData = $request->request->all('metrics');
        if (is_array($metricsData)) {
            foreach ($analysis->getMetrics() as $metric) {
                $metricId = $metric->getId();
                if (isset($metricsData[$metricId])) {
                    $newName = trim((string)$metricsData[$metricId]);
                    if (!empty($newName)) {
                        $metric->setCanonicalName($newName);
                    }
                }
            }
        }

        $analysis->setIsConfirmed(true);
        $this->entityManager->flush();

        $this->addFlash('success', 'Анализ успешно сохранен в историю');

        return $this->redirectToRoute('app_history');
    }

    /**
     * Reprocess analysis with a specific OCR model.
     *
     * This endpoint allows users to retry OCR processing with different models
     * to compare results or improve accuracy.
     */
    #[Route('/{id}/reprocess', name: 'app_analysis_reprocess', methods: ['POST'])]
    public function reprocess(int $id, Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Не авторизован'], 401);
        }

        $analysis = $this->analysisRepository->findOneByIdAndUser($id, $user);
        if (!$analysis) {
            return new JsonResponse(['error' => 'Анализ не найден'], 404);
        }

        // Parse request body for model selection
        $content = json_decode($request->getContent(), true);
        $model = $content['model'] ?? $request->request->get('model');
        
        // Also check query string just in case
        if (!$model) {
            $model = $request->query->get('model');
        }
        
        $this->logger?->info("Reprocess requested", [
            'analysisId' => $id,
            'rawContent' => $request->getContent(),
            'postData' => $request->request->all(),
            'queryData' => $request->query->all(),
            'resolvedModel' => $model
        ]);

        // Validate model
        if ($model !== null && !$this->isValidModel($model)) {
            return new JsonResponse([
                'error' => 'Неподдерживаемая модель',
                'available_models' => array_column($this->availableModels, 'name'),
            ], 400);
        }

        try {
            // Delete existing metrics
            foreach ($analysis->getMetrics() as $metric) {
                $this->entityManager->remove($metric);
            }

            // Reset analysis status and clear error message
            $analysis->setStatus(Analysis::STATUS_PENDING);
            $analysis->setErrorMessage(null);
            $analysis->setTitle(null);
            $analysis->setOcrRawText(null);
            $analysis->setIsConfirmed(false);

            $this->entityManager->flush();

            // Dispatch new OCR job with selected model
            $job = new OCRJob(
                analysisId: $analysis->getId(),
                filePath: $analysis->getFilePath(),
                userId: $user->getId(),
                model: $model
            );
            $this->messageBus->dispatch($job);

            $isAjax = $request->isXmlHttpRequest();
            if ($isAjax) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Документ поставлен в очередь на перераспознавание',
                    'model' => $model ?? 'default',
                    'analysisId' => $analysis->getId(),
                ]);
            }

            // For regular POST, redirect to status page
            return $this->redirectToRoute('app_upload_status', ['id' => $analysis->getId()]);

        } catch (\Exception $e) {
            $this->logger?->error("Reprocess failed: {$e->getMessage()}");
            
            return new JsonResponse([
                'error' => 'Ошибка при перераспознавании: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available OCR models for the UI.
     */
    #[Route('/models', name: 'app_analysis_models', methods: ['GET'])]
    public function getAvailableModels(): JsonResponse
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Не авторизован'], 401);
        }

        return new JsonResponse([
            'models' => $this->availableModels,
            'default' => $this->ocrManager->getDefaultModel(),
        ]);
    }

    /**
     * Legacy: Refine analysis with Ollama (deprecated, redirects to reprocess).
     */
    #[Route('/{id}/refine-with-ollama', name: 'app_analysis_refine_ollama', methods: ['POST'])]
    public function refineWithOllama(int $id, Request $request): Response
    {
        // Delegate to reprocess with default model
        return $this->reprocess($id, $request);
    }

    /**
     * Update analysis title (user-facing name in списках и шапке).
     */
    #[Route('/{id}/title', name: 'app_analysis_rename_title', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function renameTitle(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Не авторизован'], 401);
        }

        $analysis = $this->analysisRepository->findOneByIdAndUser($id, $user);
        if (!$analysis) {
            return new JsonResponse(['error' => 'Анализ не найден'], 404);
        }

        $raw = $request->request->get('title', '');
        $title = is_string($raw) ? trim($raw) : '';
        if ($title === '') {
            $analysis->setTitle(null);
        } else {
            if (mb_strlen($title) > 255) {
                return new JsonResponse(['error' => 'Название не длиннее 255 символов'], 400);
            }
            $analysis->setTitle($title);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'title' => $analysis->getTitle(),
        ]);
    }

    /**
     * Дата анализа (распознанная моделью или вручную).
     */
    #[Route('/{id}/analysis-date', name: 'app_analysis_update_date', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateAnalysisDate(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Не авторизован'], 401);
        }

        $analysis = $this->analysisRepository->findOneByIdAndUser($id, $user);
        if (!$analysis) {
            return new JsonResponse(['error' => 'Анализ не найден'], 404);
        }

        $raw = $request->request->get('date', '');
        $raw = \is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            $analysis->setAnalysisDate(null);
        } else {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
            if ($parsed === false) {
                return new JsonResponse(['error' => 'Ожидается дата в формате ГГГГ-ММ-ДД'], 400);
            }
            $analysis->setAnalysisDate($parsed);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'date' => $analysis->getAnalysisDate()?->format('Y-m-d'),
        ]);
    }

    /**
     * Добавить показатель вручную (после распознавания или для дополнения списка).
     */
    #[Route('/{id}/metrics', name: 'app_analysis_add_metric', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addMetric(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Не авторизован'], 401);
        }

        $analysis = $this->analysisRepository->findOneByIdAndUser($id, $user);
        if (!$analysis) {
            return new JsonResponse(['error' => 'Анализ не найден'], 404);
        }

        if ($analysis->getStatus() !== Analysis::STATUS_COMPLETED) {
            return new JsonResponse(['error' => 'Показатели можно добавлять только после завершения распознавания'], 400);
        }

        $name = trim((string) $request->request->get('name', ''));
        $value = trim((string) $request->request->get('value', ''));
        if ($name === '' || $value === '') {
            return new JsonResponse(['error' => 'Укажите название и значение показателя'], 400);
        }

        $unit = trim((string) $request->request->get('unit', ''));
        $refMin = trim((string) $request->request->get('referenceMin', ''));
        $refMax = trim((string) $request->request->get('referenceMax', ''));

        $metric = new Metric();
        $analysis->addMetric($metric);
        $metric->setName($name);
        $metric->setValue($value);
        $metric->setUnit($unit !== '' ? $unit : null);
        $metric->setReferenceMin($refMin !== '' ? $refMin : null);
        $metric->setReferenceMax($refMax !== '' ? $refMax : null);
        $metric->setIsAboveNormal(null);
        $metric->setIsBelowNormal(null);

        $canonicalName = $this->metricAliasRepository->findCanonicalName($user, $name);
        if ($canonicalName) {
            $metric->setCanonicalName($canonicalName);
        }

        $this->entityManager->persist($metric);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'metric' => [
                'id' => $metric->getId(),
                'name' => $metric->getName(),
                'canonicalName' => $metric->getCanonicalName(),
                'value' => $metric->getValue(),
                'unit' => $metric->getUnit(),
                'referenceMin' => $metric->getReferenceMin(),
                'referenceMax' => $metric->getReferenceMax(),
            ],
        ]);
    }

    /**
     * Скачать / открыть исходный загруженный файл.
     */
    #[Route('/{id}/file', name: 'app_analysis_original_file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function originalFile(int $id): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('auth_login');
        }

        $analysis = $this->analysisRepository->findOneByIdAndUser($id, $user);
        if (!$analysis) {
            throw $this->createNotFoundException('Анализ не найден');
        }

        $rel = $analysis->getFilePath();
        if (!$rel) {
            throw $this->createNotFoundException('Файл не найден');
        }

        $uploadDir = (string) $this->getParameter('upload_dir');
        $abs = $uploadDir . '/' . $rel;
        if (!is_file($abs)) {
            throw $this->createNotFoundException('Файл не найден на диске');
        }

        return $this->file($abs, basename($rel), ResponseHeaderBag::DISPOSITION_INLINE);
    }

    /**
     * Постоянные превью страниц документа (раньше лежали в /debug и удалялись по таймеру).
     */
    #[Route('/{id}/preview/{filename}', name: 'app_analysis_document_preview', methods: ['GET'], requirements: ['id' => '\d+', 'filename' => '[a-zA-Z0-9._-]+'])]
    public function documentPreview(int $id, string $filename): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $analysis = $this->analysisRepository->findOneByIdAndUser($id, $user);
        if (!$analysis) {
            throw $this->createNotFoundException('Анализ не найден');
        }

        $uploadDir = (string) $this->getParameter('upload_dir');
        $resolved = $this->resolveDocumentPreviewPath($analysis, $filename, $uploadDir);
        if ($resolved === null) {
            throw $this->createNotFoundException('Изображение не найдено');
        }

        return $this->file($resolved, $filename, ResponseHeaderBag::DISPOSITION_INLINE)
            ->setPublic()
            ->setMaxAge(3600);
    }

    /**
     * View analysis results
     */
    #[Route('/{id}', name: 'app_analysis_view', methods: ['GET'])]
    public function view(int $id): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('auth_login');
        }

        $analysis = $this->analysisRepository->findOneByIdAndUser($id, $user);
        if (!$analysis) {
            throw $this->createNotFoundException('Анализ не найден');
        }

        return $this->render('analysis/view.html.twig', [
            'analysis' => $analysis,
            'available_models' => $this->availableModels,
        ]);
    }

    /**
     * Check if a model name is valid.
     */
    private function isValidModel(string $model): bool
    {
        $validModels = array_column($this->availableModels, 'name');
        return in_array($model, $validModels, true);
    }

    /**
     * @return non-falsy-string|null absolute filesystem path
     */
    private function resolveDocumentPreviewPath(Analysis $analysis, string $filename, string $uploadDir): ?string
    {
        foreach ($analysis->getDebugImagesPathsArray() as $rel) {
            if (basename($rel) !== $filename) {
                continue;
            }

            $candidates = [];
            if (str_contains($rel, '/')) {
                $candidates[] = $uploadDir . '/' . $rel;
            } else {
                $candidates[] = $uploadDir . '/document_previews/' . $analysis->getId() . '/' . $rel;
                $candidates[] = $uploadDir . '/debug/' . $rel;
            }

            foreach ($candidates as $abs) {
                if (is_file($abs)) {
                    return $abs;
                }
            }
        }

        return null;
    }
}
