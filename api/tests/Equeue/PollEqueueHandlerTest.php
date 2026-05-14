<?php

declare(strict_types=1);

namespace App\Tests\Equeue;

use App\Entity\Equeue\EqueueRawHtml;
use App\Entity\Equeue\EqueueSnapshot;
use App\Equeue\Fetcher\EqueueFetcherInterface;
use App\Equeue\Fetcher\EqueueRawResponse;
use App\Message\Equeue\BroadcastTelegramMessage;
use App\Message\Equeue\PollEqueueMessage;
use App\MessageHandler\Equeue\PollEqueueHandler;
use App\Repository\Equeue\EqueueRawHtmlRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PollEqueueHandlerTest extends TestCase
{
    private LockFactory $lockFactory;
    private SharedLockInterface $lock;

    protected function setUp(): void
    {
        $this->lock = $this->createMock(SharedLockInterface::class);
        $this->lock->method('acquire')->willReturn(true);

        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->lockFactory->method('createLock')->willReturn($this->lock);
    }

    public function testSkipsWhenLockNotAcquired(): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $fetcher = $this->createMock(EqueueFetcherInterface::class);
        $fetcher->expects(self::never())->method('fetch');

        $handler = new PollEqueueHandler(
            $fetcher,
            $this->createMock(EqueueRawHtmlRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MessageBusInterface::class),
            $lockFactory,
            new NullLogger(),
        );

        ($handler)(new PollEqueueMessage());
    }

    public function testHttpErrorSavesSnapshotAndBroadcasts(): void
    {
        $response = new EqueueRawResponse(403, '', 'text/html', new \DateTimeImmutable());

        $fetcher = $this->createMock(EqueueFetcherInterface::class);
        $fetcher->method('fetch')->willReturn($response);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $msg) use (&$dispatched): Envelope {
                $dispatched[] = $msg;

                return new Envelope($msg);
            }
        );

        $handler = new PollEqueueHandler(
            $fetcher,
            $this->createMock(EqueueRawHtmlRepository::class),
            $em,
            $bus,
            $this->lockFactory,
            new NullLogger(),
        );

        ($handler)(new PollEqueueMessage());

        self::assertCount(1, $persisted);
        self::assertInstanceOf(EqueueSnapshot::class, $persisted[0]);
        self::assertSame(EqueueSnapshot::STATUS_HTTP_ERROR, $persisted[0]->getStatus());
        self::assertSame(403, $persisted[0]->getHttpStatus());

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(BroadcastTelegramMessage::class, $dispatched[0]);
        self::assertSame('🚨 Щось бляха, пішло не в ту дірку', $dispatched[0]->text);
    }

    public function testAlertPresentSavesHtmlAndSnapshotWithoutBroadcast(): void
    {
        $body = '<html><div>Наразі всі місця зайняті</div></html>';
        $response = new EqueueRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        $fetcher = $this->createMock(EqueueFetcherInterface::class);
        $fetcher->method('fetch')->willReturn($response);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new PollEqueueHandler(
            $fetcher,
            $this->createMock(EqueueRawHtmlRepository::class),
            $em,
            $bus,
            $this->lockFactory,
            new NullLogger(),
        );

        ($handler)(new PollEqueueMessage());

        self::assertCount(2, $persisted);

        $rawHtmlEntity = array_values(array_filter($persisted, fn ($e) => $e instanceof EqueueRawHtml))[0];
        self::assertTrue($rawHtmlEntity->isAlertPresent());
        self::assertSame($body, $rawHtmlEntity->getHtmlBody());

        $snapshot = array_values(array_filter($persisted, fn ($e) => $e instanceof EqueueSnapshot))[0];
        self::assertSame(EqueueSnapshot::STATUS_OK, $snapshot->getStatus());
    }

    public function testAlertAbsentSavesHtmlAndBroadcasts(): void
    {
        $body = '<html><p>Запис доступний</p></html>';
        $response = new EqueueRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        $fetcher = $this->createMock(EqueueFetcherInterface::class);
        $fetcher->method('fetch')->willReturn($response);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $msg) use (&$dispatched): Envelope {
                $dispatched[] = $msg;

                return new Envelope($msg);
            }
        );

        $handler = new PollEqueueHandler(
            $fetcher,
            $this->createMock(EqueueRawHtmlRepository::class),
            $em,
            $bus,
            $this->lockFactory,
            new NullLogger(),
        );

        ($handler)(new PollEqueueMessage());

        $rawHtmlEntity = array_values(array_filter($persisted, fn ($e) => $e instanceof EqueueRawHtml))[0];
        self::assertFalse($rawHtmlEntity->isAlertPresent());

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(BroadcastTelegramMessage::class, $dispatched[0]);
        self::assertStringContainsString('Вейкап Нео', $dispatched[0]->text);
    }
}
