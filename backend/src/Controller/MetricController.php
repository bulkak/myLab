<?php

namespace App\Controller;

use App\Repository\MetricRepository;
use App\Util\MetricDynamicsToken;
use App\Repository\UserRepository;
use App\Service\AuthSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/metric')]
class MetricController extends AbstractController
{
    #[Route('/suggest', name: 'app_metric_suggest', methods: ['GET'])]
    public function suggest(
        Request $request,
        MetricRepository $metricRepository,
        UserRepository $userRepository,
        AuthSessionService $sessionService
    ): JsonResponse {
        $userId = $sessionService->getUserId();
        if (!$userId) {
            return new JsonResponse([], 401);
        }

        $user = $userRepository->find($userId);
        if (!$user) {
            return new JsonResponse([], 401);
        }

        $query = $request->query->get('q', '');
        
        $conn = $metricRepository->getEntityManager()->getConnection();
        $sql = '
            SELECT DISTINCT COALESCE(NULLIF(m.canonical_name, \'\'), m.name) as name
            FROM metrics m
            JOIN analyses a ON m.analysis_id = a.id
            WHERE a.user_id = :user_id
            AND (m.name ILIKE :query OR m.canonical_name ILIKE :query)
            ORDER BY name ASC
            LIMIT 20
        ';
        
        $result = $conn->executeQuery($sql, [
            'user_id' => $user->getId(),
            'query' => '%' . $query . '%'
        ]);
        
        $names = array_column($result->fetchAllAssociative(), 'name');

        return new JsonResponse($names);
    }

    #[Route('/{id}/rename', name: 'app_metric_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rename(
        int $id,
        Request $request,
        MetricRepository $metricRepository,
        EntityManagerInterface $entityManager,
        AuthSessionService $sessionService
    ): JsonResponse {
        $userId = $sessionService->getUserId();
        if (!$userId) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $metric = $metricRepository->find($id);
        if (!$metric || $metric->getAnalysis()->getUser()->getId() !== $userId) {
            return new JsonResponse(['error' => 'Metric not found'], 404);
        }

        $newName = trim($request->request->get('name', ''));
        if (empty($newName)) {
            return new JsonResponse(['error' => 'Name cannot be empty'], 400);
        }

        $metric->setCanonicalName($newName);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'id' => $metric->getId(),
            'name' => $newName
        ]);
    }

    #[Route('/dynamics/{token}', name: 'app_metric_dynamics', requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function dynamics(string $token, MetricRepository $metricRepository, AuthSessionService $sessionService): Response
    {
        $userId = $sessionService->getUserId();
        if (!$userId) {
            return $this->redirectToRoute('auth_login');
        }

        try {
            $name = MetricDynamicsToken::decode($token);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Некорректная ссылка на показатель');
        }

        // Fetch metrics by name for the user, only from confirmed analyses, ordered by date
        $metrics = $metricRepository->createQueryBuilder('m')
            ->join('m.analysis', 'a')
            ->where('m.name = :name OR m.canonicalName = :name')
            ->andWhere('a.user = :userId')
            ->andWhere('a.isConfirmed = true')
            ->setParameter('name', $name)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        // Sort in PHP to handle COALESCE(analysisDate, createdAt) correctly
        usort($metrics, function($m1, $m2) {
            $date1 = $m1->getAnalysis()->getAnalysisDate() ?? $m1->getAnalysis()->getCreatedAt();
            $date2 = $m2->getAnalysis()->getAnalysisDate() ?? $m2->getAnalysis()->getCreatedAt();
            return $date1 <=> $date2;
        });

        return $this->render('metric/dynamics.html.twig', [
            'name' => $name,
            'metrics' => $metrics,
        ]);
    }
}
