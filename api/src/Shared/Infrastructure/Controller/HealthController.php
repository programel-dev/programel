<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller;

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

        $errorCount = count(array_filter($services, fn (string $s) => 'error' === $s));
        $status = match (true) {
            0 === $errorCount => 'ok',
            $errorCount === count($services) => 'error',
            default => 'degraded',
        };

        $httpStatus = 'ok' === $status ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse([
            'status' => $status,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'services' => $services,
        ], $httpStatus);
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
            $url = parse_url($_ENV['REDIS_URL'] ?? 'redis://redis:6379');
            $redis->connect($url['host'] ?? 'redis', (int) ($url['port'] ?? 6379));
            $redis->ping();

            return 'connected';
        } catch (\Throwable) {
            return 'error';
        }
    }
}
