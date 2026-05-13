<?php

declare(strict_types=1);

namespace App\MessageHandler\Equeue;

use App\Entity\Equeue\EqueueSnapshot;
use App\Equeue\Fetcher\EqueueFetcherInterface;
use App\Equeue\Parser\EqueueParseException;
use App\Equeue\Parser\EqueueParserInterface;
use App\Message\Equeue\EvaluateWatchMessage;
use App\Repository\Equeue\EqueueWatchRepository;
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
        private readonly EqueueParserInterface $parser,
        private readonly EntityManagerInterface $entityManager,
        private readonly EqueueWatchRepository $watchRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $lock = $this->lockFactory->createLock('equeue.poll', ttl: 120.0, autoRelease: true);
        if (!$lock->acquire()) {
            $this->logger->info('e-queue poll skipped: another worker holds the lock');

            return;
        }

        try {
            $response = $this->fetcher->fetch();
            $parserVersion = $this->parser->version();

            if (!$response->isSuccess()) {
                $this->logger->warning('e-queue fetch returned non-success status', [
                    'status' => $response->statusCode,
                ]);
                $snapshot = new EqueueSnapshot(
                    $response->fetchedAt,
                    EqueueSnapshot::STATUS_HTTP_ERROR,
                    $response->statusCode,
                    ['services' => [], 'slots' => []],
                    0,
                    $parserVersion,
                );
                $this->entityManager->persist($snapshot);
                $this->entityManager->flush();

                return;
            }

            try {
                $data = $this->parser->parse($response);
            } catch (EqueueParseException $exception) {
                $this->logger->error('e-queue parse failure', [
                    'exception' => $exception->getMessage(),
                ]);
                $snapshot = new EqueueSnapshot(
                    $response->fetchedAt,
                    EqueueSnapshot::STATUS_PARSE_ERROR,
                    $response->statusCode,
                    ['services' => [], 'slots' => [], 'error' => $exception->getMessage()],
                    0,
                    $parserVersion,
                );
                $this->entityManager->persist($snapshot);
                $this->entityManager->flush();

                return;
            }

            $payload = $data->toArray();
            $snapshot = new EqueueSnapshot(
                $response->fetchedAt,
                EqueueSnapshot::STATUS_OK,
                $response->statusCode,
                $payload,
                count($data->slots),
                $parserVersion,
            );
            $this->entityManager->persist($snapshot);
            $this->entityManager->flush();

            if (0 === $snapshot->getSlotCount()) {
                return;
            }

            $snapshotId = (int) $snapshot->getId();
            foreach ($this->watchRepository->findAllActive() as $watch) {
                $this->messageBus->dispatch(new EvaluateWatchMessage(
                    (int) $watch->getId(),
                    $snapshotId,
                ));
            }
        } finally {
            $lock->release();
        }
    }
}
