<?php

declare(strict_types=1);

namespace App\Tests\Equeue;

use App\DocumentCenter\Application\PollDocumentCenter\PollDocumentCenterHandler;
use App\DocumentCenter\Application\PollDocumentCenter\PollDocumentCenterMessage;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterRawHtmlRepository;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterSnapshotRepository;
use App\DocumentCenter\Infrastructure\Fetcher\DocumentCenterFetcherInterface;
use App\Monitoring\Infrastructure\MonitoringConfigRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

final class PollEqueueHandlerDisabledTest extends TestCase
{
    public function testHandlerSkipsFetchWhenMonitoringIsDisabled(): void
    {
        $monitoring = $this->createMock(MonitoringConfigRepositoryInterface::class);
        $monitoring->method('isEnabled')->willReturn(false);

        $fetcher = $this->createMock(DocumentCenterFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->never())->method('createLock');

        $handler = new PollDocumentCenterHandler(
            fetcher: $fetcher,
            rawHtmlRepository: $this->createMock(DocumentCenterRawHtmlRepository::class),
            entityManager: $this->createMock(EntityManagerInterface::class),
            messageBus: $this->createMock(MessageBusInterface::class),
            lockFactory: $lockFactory,
            snapshotRepository: $this->createMock(DocumentCenterSnapshotRepository::class),
            logger: $this->createMock(LoggerInterface::class),
            monitoringConfigRepository: $monitoring,
        );

        $handler(new PollDocumentCenterMessage());
    }
}
