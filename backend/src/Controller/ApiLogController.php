<?php

namespace App\Controller;

use App\Repository\ApiLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ApiLogController extends AbstractController
{
    #[Route('/api-logs', name: 'app_api_logs')]
    public function index(ApiLogRepository $apiLogRepository): Response
    {
        $logs = $apiLogRepository->findBy([], ['createdAt' => 'DESC'], 50);

        return $this->render('api_log/index.html.twig', [
            'logs' => $logs,
        ]);
    }

    #[Route('/api-logs/{id}', name: 'app_api_log_show')]
    public function show(int $id, ApiLogRepository $apiLogRepository): Response
    {
        $log = $apiLogRepository->find($id);

        if (!$log) {
            throw $this->createNotFoundException('Log not found');
        }

        return $this->render('api_log/show.html.twig', [
            'log' => $log,
        ]);
    }
}
