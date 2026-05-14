<?php

declare(strict_types=1);

namespace App\MessageHandler\Equeue;

use App\Entity\Equeue\EqueueRawHtml;
use App\Entity\Equeue\EqueueSnapshot;
use App\Equeue\Fetcher\EqueueFetcherInterface;
use App\Message\Equeue\BroadcastTelegramMessage;
use App\Message\Equeue\PollEqueueMessage;
use App\Repository\Equeue\EqueueRawHtmlRepository;
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
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PollEqueueMessage $message): void
    {
        $lock = $this->lockFactory->createLock('equeue.poll', ttl: 120.0, autoRelease: true);
        if (!$lock->acquire()) {
            $this->logger->info('e-queue poll skipped: another worker holds the lock');

            return;
        }

        try {
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
                    'alert-detection-v1',
                ));
                $this->entityManager->flush();
                $this->messageBus->dispatch(new BroadcastTelegramMessage('🚨 Щось бляха, пішло не в ту дірку'));

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
                'alert-detection-v1',
            ));
            $this->entityManager->flush();

            if (!$alertPresent) {
                $this->logger->info('e-queue alert absent — broadcasting notification');
                $this->messageBus->dispatch(new BroadcastTelegramMessage(
                    "⚡️ Вейкап Нео, стан змінився!\nhttps://munich.pasport.org.ua/solutions/e-queue"
                ));
            }
        } finally {
            $lock->release();
        }
    }
}
