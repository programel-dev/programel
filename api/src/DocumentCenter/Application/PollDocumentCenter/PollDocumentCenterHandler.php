<?php

declare(strict_types=1);

namespace App\DocumentCenter\Application\PollDocumentCenter;

use App\DocumentCenter\Application\BroadcastSlotsAvailable\BroadcastSlotsAvailableMessage;
use App\DocumentCenter\Domain\DocumentCenterRawHtml;
use App\DocumentCenter\Domain\DocumentCenterSlot;
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
        private readonly DocumentCenterFetcherInterface $slotScraper,
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

            $alertPresent = str_contains($response->body, 'Наразі всі місця зайняті');

            $this->rawHtmlRepository->deleteOlderThan(new \DateTimeImmutable('-8 hours'));
            $this->entityManager->persist(new DocumentCenterRawHtml($response->fetchedAt, $alertPresent, $response->body));

            $this->entityManager->persist(new DocumentCenterSnapshot(
                $response->fetchedAt,
                DocumentCenterSnapshot::STATUS_OK,
                $response->statusCode,
                ['alertPresent' => $alertPresent, 'slots' => []],
                0,
                'cloudflare-bypass-v1',
            ));

            $slotDate = null;
            $slotList = [];

            if ($this->monitoringConfigRepository->isSlotScrapingEnabled()) {
                [$slotDate, $slotList] = $this->scrapeSlots();
            }

            $this->entityManager->flush();

            $wasAlertPresent = $previous?->getPayload()['alertPresent'] ?? null;
            if (true === $wasAlertPresent && false === $alertPresent) {
                $this->messageBus->dispatch(new BroadcastSlotsAvailableMessage($slotDate, $slotList));
            }
        } finally {
            $lock->release();
        }
    }

    /** @return array{string|null, list<string>} */
    private function scrapeSlots(): array
    {
        try {
            $slotResponse = $this->slotScraper->fetch();
            if (!$slotResponse->isSuccess()) {
                return [null, []];
            }

            $data = json_decode($slotResponse->body, true);
            if (!is_array($data) || !($data['success'] ?? false)) {
                return [null, []];
            }

            $date = isset($data['dateFormatted']) && is_string($data['dateFormatted']) ? $data['dateFormatted'] : null;
            $slots = isset($data['slots']) && is_array($data['slots']) ? array_values($data['slots']) : [];

            if (null !== $date && [] !== $slots) {
                $this->entityManager->persist(new DocumentCenterSlot(
                    new \DateTimeImmutable(),
                    ['date' => $data['date'] ?? $date, 'slots' => $slots],
                ));
            }

            return [$date, $slots];
        } catch (\Throwable $e) {
            $this->logger->warning('slot scraper failed', ['exception' => $e->getMessage()]);

            return [null, []];
        }
    }
}
