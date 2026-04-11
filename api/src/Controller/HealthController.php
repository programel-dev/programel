<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[Route('/api/health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $services = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        $allConnected = !in_array('error', $services, true);

        return new JsonResponse([
            'status' => $allConnected ? 'ok' : 'error',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'services' => $services,
        ], $allConnected ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function checkDatabase(): string
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            return 'connected';
        } catch (\Throwable) {
            return 'error';
        }
    }

    private function checkRedis(): string
    {
        try {
            $redis = new \Redis();
            $redis->connect($_ENV['REDIS_URL'] ?? 'redis://redis:6379');
            $redis->ping();
            return 'connected';
        } catch (\Throwable) {
            return 'error';
        }
    }
}
