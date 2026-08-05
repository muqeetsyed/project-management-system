<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            $database = 'connected';
        } catch (\Throwable) {
            $database = 'unreachable';
        }

        return new JsonResponse([
            'status' => 'ok',
            'database' => $database,
        ]);
    }
}
