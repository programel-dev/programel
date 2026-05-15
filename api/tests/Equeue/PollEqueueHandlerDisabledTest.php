<?php

declare(strict_types=1);

namespace App\Tests\Equeue;

use App\Equeue\Fetcher\EqueueFetcherInterface;
use App\Message\Equeue\PollEqueueMessage;
use App\MessageHandler\Equeue\PollEqueueHandler;
use App\Repository\Equeue\EqueueRawHtmlRepository;
use App\Repository\Equeue\EqueueSnapshotRepository;
use App\Repository\MonitoringConfigRepositoryInterface;
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

        $fetcher = $this->createMock(EqueueFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->never())->method('createLock');

        $handler = new PollEqueueHandler(
            fetcher: $fetcher,
            rawHtmlRepository: $this->createMock(EqueueRawHtmlRepository::class),
            entityManager: $this->createMock(EntityManagerInterface::class),
            messageBus: $this->createMock(MessageBusInterface::class),
            lockFactory: $lockFactory,
            snapshotRepository: $this->createMock(EqueueSnapshotRepository::class),
            logger: $this->createMock(LoggerInterface::class),
            monitoringConfigRepository: $monitoring,
        );

        $handler(new PollEqueueMessage());
    }
}
