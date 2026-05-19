<?php

declare(strict_types=1);

namespace App\DocumentCenter\Application\PollDocumentCenter;

use App\DocumentCenter\Application\BroadcastSlotsAvailable\BroadcastSlotsAvailableMessage;
use App\DocumentCenter\Domain\DocumentCenterRawHtml;
use App\DocumentCenter\Domain\DocumentCenterSnapshot;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterRawHtmlRepository;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterSnapshotRepository;
use App\DocumentCenter\Infrastructure\Fetcher\DocumentCenterFetcherInterface;
use App\Monitoring\Infrastructure\MonitoringConfigRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class PollDocumentCenterHandler
{
    public function __construct(
        private readonly DocumentCenterFetcherInterface $fetcher,
        private readonly DocumentCenterRawHtmlRepository $rawHtmlRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LockFactory $lockFactory,
        private readonly DocumentCenterSnapshotRepository $snapshotRepository,
        private readonly LoggerInterface $logger,
        private readonly MonitoringConfigRepositoryInterface $monitoringConfigRepository,
    ) {
    }

    public function __invoke(PollDocumentCenterMessage $message): void
    {
        if (!$this->monitoringConfigRepository->isEnabled()) {
            $this->logger->info('equeue polling disabled, skipping');

            return;
        }

        $lock = $this->lockFactory->createLock('equeue.poll', ttl: 120.0, autoRelease: true);
        if (!$lock->acquire()) {
            $this->logger->info('e-queue poll skipped: another worker holds the lock');

            return;
        }

        try {
            $previous = $this->snapshotRepository->findLatest();
            $response = $this->fetcher->fetch();

            $this->snapshotRepository->deleteOlderThan(new \DateTimeImmutable('-8 hours'));

            if (!$response->isSuccess()) {
                $this->logger->warning('e-queue fetch returned non-success status', [
                    'status' => $response->statusCode,
                ]);
                $this->entityManager->persist(new DocumentCenterSnapshot(
                    $response->fetchedAt,
                    DocumentCenterSnapshot::STATUS_HTTP_ERROR,
                    $response->statusCode,
                    [],
                    0,
                    'cloudflare-bypass-v1',
                ));
                $this->entityManager->flush();

                return;
            }

            [$alertPresent, $slots, $parserVersion] = $this->parseResponse($response->body);

            $this->rawHtmlRepository->deleteOlderThan(new \DateTimeImmutable('-8 hours'));
            $this->entityManager->persist(new DocumentCenterRawHtml($response->fetchedAt, $alertPresent, $response->body));

            $this->entityManager->persist(new DocumentCenterSnapshot(
                $response->fetchedAt,
                DocumentCenterSnapshot::STATUS_OK,
                $response->statusCode,
                ['alertPresent' => $alertPresent, 'slots' => $slots],
                0,
                $parserVersion,
            ));
            $this->entityManager->flush();

            $wasAlertPresent = $previous?->getPayload()['alertPresent'] ?? null;
            if (true === $wasAlertPresent && false === $alertPresent) {
                $this->messageBus->dispatch(new BroadcastSlotsAvailableMessage());
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{bool, list<array{date: string, times: list<string>}>, string}
     */
    private function parseResponse(string $body): array
    {
        $data = json_decode($body, true);
        if (is_array($data) && array_key_exists('slots', $data)) {
            $slots = $data['slots'] ?? [];

            return [empty($slots), $slots, 'playwright-slot-v1'];
        }

        return [str_contains($body, 'Наразі всі місця зайняті'), [], 'cloudflare-bypass-v1'];
    }
}
