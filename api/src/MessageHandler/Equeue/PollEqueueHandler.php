<?php

declare(strict_types=1);

namespace App\MessageHandler\Equeue;

use App\Entity\Equeue\EqueueRawHtml;
use App\Entity\Equeue\EqueueSnapshot;
use App\Equeue\Fetcher\EqueueFetcherInterface;
use App\Message\Equeue\BroadcastTelegramMessage;
use App\Message\Equeue\PollEqueueMessage;
use App\Repository\Equeue\EqueueRawHtmlRepository;
use App\Repository\Equeue\EqueueSnapshotRepository;
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

                if (null === $previous || EqueueSnapshot::STATUS_HTTP_ERROR !== $previous->getStatus()) {
                    $this->messageBus->dispatch(new BroadcastTelegramMessage('🚨 Щось бляха, пішло не в ту дірку'));
                }

                return;
            }

            $alertPresent = str_contains($response->body, 'Наразі всі місця зайняті');

            $this->rawHtmlRepository->deleteOlderThan(new \DateTimeImmutable('-8 hours'));
            $this->entityManager->persist(new EqueueRawHtml($response->fetchedAt, $alertPresent, $response->body));
            $this->entityManager->persist(new EqueueSnapshot(
                $response->fetchedAt,
                EqueueSnapshot::STATUS_OK,
                $response->statusCode,
                ['alertPresent' => $alertPresent],
                0,
                'cloudflare-bypass-v1',
            ));
            $this->entityManager->flush();

            if (!$alertPresent) {
                $previousAlertPresent = $previous?->getPayload()['alertPresent'] ?? null;
                if (false !== $previousAlertPresent) {
                    $this->logger->info('e-queue alert absent — state transition, broadcasting');
                    $this->messageBus->dispatch(new BroadcastTelegramMessage(
                        "⚡️ Вейкап Нео, стан змінився!\nhttps://munich.pasport.org.ua/solutions/e-queue"
                    ));
                }
            }
        } finally {
            $lock->release();
        }
    }
}
