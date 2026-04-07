<?php

namespace App\Controller;

use App\Entity\Analysis;
use App\Entity\User;
use App\Repository\AnalysisRepository;
use App\Repository\MetricRepository;
use App\Repository\UserRepository;
use App\Service\AuthSessionService;
use App\Util\MetricDynamicsToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/history')]
class HistoryController extends AbstractController
{
    private AuthSessionService $sessionService;
    private UserRepository $userRepository;
    private AnalysisRepository $analysisRepository;
    private MetricRepository $metricRepository;

    public function __construct(
        AuthSessionService $sessionService,
        UserRepository $userRepository,
        AnalysisRepository $analysisRepository,
        MetricRepository $metricRepository
    ) {
        $this->sessionService = $sessionService;
        $this->userRepository = $userRepository;
        $this->analysisRepository = $analysisRepository;
        $this->metricRepository = $metricRepository;
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
     * History page with list of all analyses
     */
    #[Route('', name: 'app_history', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('auth_login');
        }

        $confirmedAnalyses = $this->analysisRepository->findBy(
            ['user' => $user, 'isConfirmed' => true],
            ['createdAt' => 'DESC']
        );
        
        $tasks = $this->analysisRepository->findBy(
            ['user' => $user, 'isConfirmed' => false],
            ['createdAt' => 'DESC']
        );
        
        // Get all unique metric names for search autocomplete
        $metricNames = $this->metricRepository->getUniqueMetricNames($user);

        return $this->render('history/index.html.twig', [
            'confirmedAnalyses' => $confirmedAnalyses,
            'tasks' => $tasks,
            'metricNames' => $metricNames,
        ]);
    }

    /**
     * Search page for finding specific metrics
     */
    #[Route('/search', name: 'app_history_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('auth_login');
        }

        $query = $request->query->get('q', '');
        $results = [];

        if (!empty($query)) {
            // Search metrics by name
            $metrics = $this->metricRepository->searchByName($user, $query);
            
            // Group by metric name
            $grouped = [];
            foreach ($metrics as $metric) {
                $name = $metric->getCanonicalName() ?: $metric->getName();
                if (!isset($grouped[$name])) {
                    $grouped[$name] = [
                        'name' => $name,
                        'metrics' => [],
                    ];
                }
                $grouped[$name]['metrics'][] = $metric;
            }
            $results = $grouped;
        }

        // Get all metric names for autocomplete
        $metricNames = array_column($this->metricRepository->getUniqueMetricNames($user), 'name');

        return $this->render('history/search.html.twig', [
            'query' => $query,
            'results' => $results,
            'metricNames' => $metricNames,
        ]);
    }

    /**
     * View metric history - all values over time for a specific metric
     */
    #[Route('/metric/{token}', name: 'app_history_metric', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function metricHistory(string $token): Response
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return $this->redirectToRoute('auth_login');
        }

        try {
            $name = MetricDynamicsToken::decode($token);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Некорректная ссылка на показатель');
        }

        // Get all metrics with this name
        $metrics = $this->metricRepository->getMetricHistory($user, $name);
        
        if (empty($metrics)) {
            throw $this->createNotFoundException('Показатель не найден');
        }

        // Prepare data for the chart
        $chartData = $this->prepareChartData($metrics);
        
        // Get reference ranges from the most recent metric
        $latestMetric = $metrics[0];
        $referenceRange = [
            'min' => $latestMetric->getReferenceMin(),
            'max' => $latestMetric->getReferenceMax(),
        ];

        return $this->render('history/metric.html.twig', [
            'metricName' => $name,
            'metrics' => $metrics,
            'chartData' => $chartData,
            'referenceRange' => $referenceRange,
        ]);
    }

    /**
     * API endpoint: Get metric history data as JSON
     */
    #[Route('/api/metric/{token}', name: 'app_history_metric_api', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function metricHistoryApi(string $token): JsonResponse
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Не авторизован'], 401);
        }

        try {
            $name = MetricDynamicsToken::decode($token);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Некорректный токен'], 400);
        }

        $metrics = $this->metricRepository->getMetricHistory($user, $name);
        
        if (empty($metrics)) {
            return new JsonResponse(['error' => 'Показатель не найден'], 404);
        }

        $chartData = $this->prepareChartData($metrics);
        
        return new JsonResponse([
            'name' => $name,
            'data' => $chartData,
            'unit' => $metrics[0]->getUnit(),
        ]);
    }

    /**
     * Prepare chart data from metrics
     */
    private function prepareChartData(array $metrics): array
    {
        $data = [];
        
        // Reverse to get chronological order
        $metrics = array_reverse($metrics);
        
        foreach ($metrics as $metric) {
            $value = $this->parseNumericValue($metric->getValue());
            
            $date = $metric->getAnalysis()->getAnalysisDate() 
                ?? $metric->getAnalysis()->getCreatedAt();
            
            $data[] = [
                'date' => $date->format('Y-m-d'),
                'dateDisplay' => $date->format('d.m.Y'),
                'value' => $value,
                'rawValue' => $metric->getValue(),
                'unit' => $metric->getUnit(),
                'analysisId' => $metric->getAnalysis()->getId(),
                'isAboveNormal' => $metric->isAboveNormal(),
                'isBelowNormal' => $metric->isBelowNormal(),
            ];
        }
        
        return $data;
    }

    /**
     * Parse numeric value from string
     */
    private function parseNumericValue(string $value): ?float
    {
        // Clean the value
        $clean = preg_replace('/[^\d.\-\,]/', '', $value);
        $clean = str_replace(',', '.', $clean);
        
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }
        
        return is_numeric($clean) ? (float)$clean : null;
    }
}
