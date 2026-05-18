<?php

declare(strict_types=1);

namespace App\MessageHandler\Equeue;

use App\Entity\Equeue\EqueueRawHtml;
use App\Entity\Equeue\EqueueSnapshot;
use App\Equeue\Fetcher\EqueueFetcherInterface;
use App\Message\Equeue\BroadcastTelegramMessage;
use App\Message\Equeue\EvaluateWatchMessage;
use App\Message\Equeue\PollEqueueMessage;
use App\Repository\Equeue\EqueueRawHtmlRepository;
use App\Repository\Equeue\EqueueSnapshotRepository;
use App\Repository\Equeue\EqueueWatchRepository;
use App\Repository\MonitoringConfigRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class PollEqueueHandler
{
    public function __construct(
        private readonly EqueueFetcherInterface $fetcher,
        private readonly EqueueRawHtmlRepository $rawHtmlRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LockFactory $lockFactory,
        private readonly EqueueSnapshotRepository $snapshotRepository,
        private readonly EqueueWatchRepository $watchRepository,
        private readonly LoggerInterface $logger,
        private readonly MonitoringConfigRepositoryInterface $monitoringConfigRepository,
    ) {
    }

    public function __invoke(PollEqueueMessage $message): void
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
                $this->entityManager->persist(new EqueueSnapshot(
                    $response->fetchedAt,
                    EqueueSnapshot::STATUS_HTTP_ERROR,
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
            $this->entityManager->persist(new EqueueRawHtml($response->fetchedAt, $alertPresent, $response->body));
            $snapshot = new EqueueSnapshot(
                $response->fetchedAt,
                EqueueSnapshot::STATUS_OK,
                $response->statusCode,
                ['alertPresent' => $alertPresent, 'slots' => $slots],
                0,
                $parserVersion,
            );
            $this->entityManager->persist($snapshot);
            $this->entityManager->flush();

            if (!$alertPresent) {
                $snapshotId = (int) $snapshot->getId();
                foreach ($this->watchRepository->findAllActive() as $watch) {
                    $this->messageBus->dispatch(new EvaluateWatchMessage($watch->getId(), $snapshotId));
                }

                $previousAlertPresent = $previous?->getPayload()['alertPresent'] ?? null;
                if (false !== $previousAlertPresent) {
                    $this->logger->info('e-queue alert absent — state transition, broadcasting');
                    $text = !empty($slots)
                        ? $this->formatSlotMessage($slots)
                        : "⚡️ Вейкап Нео, стан змінився!\nhttps://munich.pasport.org.ua/solutions/e-queue";
                    $this->messageBus->dispatch(new BroadcastTelegramMessage($text));
                }
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

    private const MONTHS_UA = [
        1 => 'січня', 2 => 'лютого', 3 => 'березня', 4 => 'квітня',
        5 => 'травня', 6 => 'червня', 7 => 'липня', 8 => 'серпня',
        9 => 'вересня', 10 => 'жовтня', 11 => 'листопада', 12 => 'грудня',
    ];

    /** @param list<array{date: string, times: list<string>}> $slots */
    private function formatSlotMessage(array $slots): string
    {
        $lines = [];
        foreach ($slots as $slot) {
            $dt = new \DateTimeImmutable($slot['date']);
            $day = $dt->format('j');
            $month = self::MONTHS_UA[(int) $dt->format('n')];
            $lines[] = "{$day} {$month}: ".implode(', ', $slot['times']);
        }

        return "🗓 Є вільні місця в черзі!\n\n"
            .implode("\n", $lines)
            ."\n\n👉 https://munich.pasport.org.ua/solutions/e-queue";
    }
}
