<?php

namespace App\Controller;

use App\Entity\Analysis;
use App\Entity\User;
use App\Message\OCRJob;
use App\Repository\AnalysisRepository;
use App\Repository\UserRepository;
use App\Service\AuthSessionService;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/upload')]
class UploadController extends AbstractController
{
    private AuthSessionService $sessionService;
    private FileUploadService $uploadService;
    private UserRepository $userRepository;
    private AnalysisRepository $analysisRepository;
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $messageBus;
    private ValidatorInterface $validator;
    private LoggerInterface $logger;

    public function __construct(
        AuthSessionService $sessionService,
        FileUploadService $uploadService,
        UserRepository $userRepository,
        AnalysisRepository $analysisRepository,
        EntityManagerInterface $entityManager,
        MessageBusInterface $messageBus,
        ValidatorInterface $validator,
        LoggerInterface $logger
    ) {
        $this->sessionService = $sessionService;
        $this->uploadService = $uploadService;
        $this->userRepository = $userRepository;
        $this->analysisRepository = $analysisRepository;
        $this->entityManager = $entityManager;
        $this->messageBus = $messageBus;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    /**
     * Get current user or redirect to login
     */
    private function getCurrentUser(): ?User
    {
        $userId = $this->sessionService->getUserId();
        if (!$userId) {
            return null;
        }
        return $this->userRepository->find($userId);
    }

    /**
     * Upload page
     */
    #[Route('', name: 'app_upload', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('auth_login');
        }

        return $this->render('upload/index.html.twig', [
            'maxUploadSize' => $this->uploadService->getMaxUploadSize(),
        ]);
    }

    /**
     * Handle file upload
     */
    #[Route('', name: 'app_upload_process', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $isAjax = $request->isXmlHttpRequest() || in_array('application/json', $request->getAcceptableContentTypes());

        $user = $this->getCurrentUser();
        if (!$user) {
            if ($isAjax) {
                return new JsonResponse(['error' => 'Не авторизован'], 401);
            }
            return $this->redirectToRoute('auth_login');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file) {
            if ($isAjax) {
                return new JsonResponse(['error' => 'Файл не выбран'], 400);
            }
            return $this->render('upload/index.html.twig', [
                'error' => 'Файл не выбран',
                'maxUploadSize' => $this->uploadService->getMaxUploadSize(),
            ]);
        }

        try {
            // Upload file
            $uploadInfo = $this->uploadService->upload($file, $user->getId());

            // Create analysis record
            $analysis = new Analysis();
            $analysis->setUser($user);
            $analysis->setFilePath($uploadInfo['path']);
            $analysis->setStatus(Analysis::STATUS_PENDING);

            $errors = $this->validator->validate($analysis);
            if (count($errors) > 0) {
                // Delete file if validation fails
                $this->uploadService->delete($uploadInfo['path']);
                throw new \Exception('Ошибка валидации: ' . $errors[0]->getMessage());
            }

            $this->entityManager->persist($analysis);
            $this->entityManager->flush();

            // Send to OCR queue
            $job = new OCRJob(
                $analysis->getId(),
                $uploadInfo['path'],
                $user->getId()
            );
            
            $this->logger->info("Dispatching OCRJob", [
                'analysis_id' => $analysis->getId(),
                'file_path' => $uploadInfo['path'],
            ]);
            
            $envelope = $this->messageBus->dispatch($job);
            
            $this->logger->info("OCRJob dispatched", [
                'message_id' => $job->getAnalysisId(),
            ]);

            $response = [
                'success' => true,
                'analysisId' => $analysis->getId(),
                'message' => 'Файл загружен и поставлен в очередь на обработку',
            ];

            if ($isAjax) {
                return new JsonResponse($response);
            }

            // Redirect to processing status page
            return $this->redirectToRoute('app_upload_status', [
                'id' => $analysis->getId(),
            ]);

        } catch (\Exception $e) {
            $error = $e->getMessage();
            
            if ($isAjax) {
                return new JsonResponse(['error' => $error], 400);
            }

            return $this->render('upload/index.html.twig', [
                'error' => $error,
                'maxUploadSize' => $this->uploadService->getMaxUploadSize(),
            ]);
        }
    }

    /**
     * Check processing status (for HTMX polling)
     */
    #[Route('/status/{id}', name: 'app_upload_status', methods: ['GET'])]
    public function status(int $id): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('auth_login');
        }

        $analysis = $this->analysisRepository->findOneByIdAndUser($id, $user);
        if (!$analysis) {
            throw $this->createNotFoundException('Анализ не найден');
        }

        // If completed, redirect to results
        if ($analysis->getStatus() === Analysis::STATUS_COMPLETED) {
            return $this->redirectToRoute('app_analysis_view', ['id' => $id]);
        }

        // If error, redirect to history
        if ($analysis->getStatus() === Analysis::STATUS_ERROR) {
            return $this->redirectToRoute('app_history');
        }

        return $this->render('upload/status.html.twig', [
            'analysis' => $analysis,
        ]);
    }

    /**
     * API endpoint for checking status (for HTMX polling)
     */
    #[Route('/status/{id}/check', name: 'app_upload_status_check', methods: ['GET'])]
    public function statusCheck(int $id): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Не авторизован'], 401);
        }

        $analysis = $this->analysisRepository->findOneByIdAndUser($id, $user);
        if (!$analysis) {
            return new JsonResponse(['error' => 'Анализ не найден'], 404);
        }

        $status = $analysis->getStatus();
        
        // Determine redirect URL based on status
        $redirectUrl = null;
        if ($status === Analysis::STATUS_COMPLETED) {
            $redirectUrl = $this->generateUrl('app_analysis_view', ['id' => $id]);
        } elseif ($status === Analysis::STATUS_ERROR) {
            $redirectUrl = $this->generateUrl('app_history');
        }

        // For HTMX - if completed or error, redirect
        if ($redirectUrl) {
            return new Response('', 200, [
                'HX-Redirect' => $redirectUrl,
            ]);
        }

        return $this->render('upload/_status_fragment.html.twig', [
            'analysis' => $analysis,
        ]);
    }

    /**
     * Serve debug image from temporary directory
     */
    #[Route('/debug-image/{filename}/view', name: 'app_upload_debug_image', methods: ['GET'])]
    public function debugImage(string $filename): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            throw $this->createNotFoundException('Не авторизован');
        }

        // Security: only allow debug_*.png files from /debug
        if (!preg_match('/^debug_(\d+_)?page_\d+\.(png|jpg|jpeg|gif)$/', $filename)) {
            throw $this->createNotFoundException('Файл не найден');
        }

        $debugDir = $this->getParameter('upload_dir') . '/debug';
        $filepath = $debugDir . '/' . $filename;

        if (!file_exists($filepath) || !is_file($filepath)) {
            throw $this->createNotFoundException('Изображение не найдено');
        }

        $response = new Response(file_get_contents($filepath));
        $response->headers->set('Content-Type', mime_content_type($filepath) ?: 'image/png');
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'request_stack' => 'Symfony\Component\HttpFoundation\RequestStack',
        ]);
    }
}
