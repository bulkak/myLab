<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AnalysisRepository;
use App\Repository\UserRepository;
use App\Service\AuthSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    private AuthSessionService $sessionService;
    private UserRepository $userRepository;

    public function __construct(
        AuthSessionService $sessionService,
        UserRepository $userRepository
    ) {
        $this->sessionService = $sessionService;
        $this->userRepository = $userRepository;
    }

    /**
     * Get current user or redirect to login
     */
    private function getCurrentUserOrRedirect(): ?User
    {
        if (!$this->sessionService->isLoggedIn()) {
            return null;
        }
        return $this->userRepository->find($this->sessionService->getUserId());
    }

    /**
     * Main dashboard page
     */
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function dashboard(AnalysisRepository $analysisRepository, \App\Repository\MetricRepository $metricRepository): Response
    {
        $user = $this->getCurrentUserOrRedirect();
        if (!$user) {
            return $this->redirectToRoute('auth_login');
        }

        $recentMetrics = $metricRepository->createQueryBuilder('m')
            ->join('m.analysis', 'a')
            ->where('a.user = :userId')
            ->andWhere('a.isConfirmed = true')
            ->setParameter('userId', $user->getId())
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(15)
            ->getQuery()
            ->getResult();

        return $this->render('dashboard/index.html.twig', [
            'recentMetrics' => $recentMetrics,
        ]);
    }
}
