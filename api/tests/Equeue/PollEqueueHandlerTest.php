<?php

declare(strict_types=1);

namespace App\Tests\Equeue;

use App\Entity\Equeue\EqueueRawHtml;
use App\Entity\Equeue\EqueueSnapshot;
use App\Equeue\Fetcher\EqueueFetcherInterface;
use App\Equeue\Fetcher\EqueueRawResponse;
use App\Message\Equeue\PollEqueueMessage;
use App\MessageHandler\Equeue\PollEqueueHandler;
use App\Repository\Equeue\EqueueRawHtmlRepository;
use App\Repository\Equeue\EqueueSnapshotRepository;
use App\Repository\MonitoringConfigRepositoryInterface;
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

        $handler = $this->makeHandler(
            fetcher: $fetcher,
            lockFactory: $lockFactory,
        );

        ($handler)(new PollEqueueMessage());
    }

    // --- HTTP error cases ---

    public function testFirstPollHttpErrorSilent(): void
    {
        $response = new EqueueRawResponse(403, '', 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(
            response: $response,
            previousSnapshot: null,
        );

        self::assertCount(1, $persisted);
        self::assertInstanceOf(EqueueSnapshot::class, $persisted[0]);
        self::assertSame(EqueueSnapshot::STATUS_HTTP_ERROR, $persisted[0]->getStatus());
        self::assertSame(403, $persisted[0]->getHttpStatus());
        self::assertSame('cloudflare-bypass-v1', $persisted[0]->getParserVersion());
        self::assertCount(0, $dispatched);
    }

    public function testOkToHttpErrorSilent(): void
    {
        $previous = $this->makeSnapshot(EqueueSnapshot::STATUS_OK, 200, ['alertPresent' => true]);
        $response = new EqueueRawResponse(503, '', '', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        self::assertCount(1, $persisted);
        self::assertSame(EqueueSnapshot::STATUS_HTTP_ERROR, $persisted[0]->getStatus());
        self::assertCount(0, $dispatched);
    }

    public function testConsecutiveHttpErrorsSilent(): void
    {
        $previous = $this->makeSnapshot(EqueueSnapshot::STATUS_HTTP_ERROR, 403, []);
        $response = new EqueueRawResponse(403, '', '', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        self::assertCount(1, $persisted);
        self::assertSame(EqueueSnapshot::STATUS_HTTP_ERROR, $persisted[0]->getStatus());
        self::assertCount(0, $dispatched);
    }

    // --- Alert present (silence) ---

    public function testAlertPresentSavesHtmlAndSnapshotWithoutBroadcast(): void
    {
        $body = '<html><div>Наразі всі місця зайняті</div></html>';
        $response = new EqueueRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        $cutoffs = [];
        $rawHtmlRepo = $this->createMock(EqueueRawHtmlRepository::class);
        $rawHtmlRepo->expects(self::once())->method('deleteOlderThan')
            ->willReturnCallback(function (\DateTimeImmutable $cutoff) use (&$cutoffs): void {
                $cutoffs[] = $cutoff;
            });

        $before = new \DateTimeImmutable('-8 hours');
        [$persisted, $dispatched] = $this->invoke(
            response: $response,
            previousSnapshot: null,
            rawHtmlRepo: $rawHtmlRepo,
        );
        $after = new \DateTimeImmutable('-8 hours');

        self::assertCount(2, $persisted);

        $raw = array_values(array_filter($persisted, fn ($e) => $e instanceof EqueueRawHtml))[0];
        self::assertTrue($raw->isAlertPresent());
        self::assertSame($body, $raw->getHtmlBody());

        $snap = array_values(array_filter($persisted, fn ($e) => $e instanceof EqueueSnapshot))[0];
        self::assertSame(EqueueSnapshot::STATUS_OK, $snap->getStatus());
        self::assertSame('cloudflare-bypass-v1', $snap->getParserVersion());

        self::assertCount(0, $dispatched);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $cutoffs[0]->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $cutoffs[0]->getTimestamp());
    }

    // --- Alert absent (broadcast) ---

    public function testFirstPollAlertAbsentBroadcasts(): void
    {
        $body = '<html><p>Запис доступний</p></html>';
        $response = new EqueueRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: null);

        $snap = array_values(array_filter($persisted, fn ($e) => $e instanceof EqueueSnapshot))[0];
        self::assertSame('cloudflare-bypass-v1', $snap->getParserVersion());

        self::assertCount(1, $dispatched);
        self::assertStringContainsString('Вейкап Нео', $dispatched[0]->text);
    }

    public function testAlertPresentToAbsentBroadcasts(): void
    {
        $previous = $this->makeSnapshot(EqueueSnapshot::STATUS_OK, 200, ['alertPresent' => true]);
        $body = '<html><p>Вільно</p></html>';
        $response = new EqueueRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        self::assertCount(1, $dispatched);
        self::assertStringContainsString('Вейкап Нео', $dispatched[0]->text);
    }

    public function testConsecutiveAlertAbsentSilent(): void
    {
        $previous = $this->makeSnapshot(EqueueSnapshot::STATUS_OK, 200, ['alertPresent' => false]);
        $body = '<html><p>Вільно</p></html>';
        $response = new EqueueRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        self::assertCount(0, $dispatched);
    }

    public function testHttpErrorToAlertAbsentBroadcasts(): void
    {
        $previous = $this->makeSnapshot(EqueueSnapshot::STATUS_HTTP_ERROR, 403, []);
        $body = '<html><p>Вільно</p></html>';
        $response = new EqueueRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        self::assertCount(1, $dispatched);
        self::assertStringContainsString('Вейкап Нео', $dispatched[0]->text);
    }

    public function testHttpErrorToAlertPresentSilent(): void
    {
        $previous = $this->makeSnapshot(EqueueSnapshot::STATUS_HTTP_ERROR, 403, []);
        $body = '<html><div>Наразі всі місця зайняті</div></html>';
        $response = new EqueueRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        self::assertCount(0, $dispatched);
    }

    // --- Helpers ---

    /**
     * @return array{list<object>, list<object>}
     */
    private function invoke(
        EqueueRawResponse $response,
        ?EqueueSnapshot $previousSnapshot,
        ?EqueueRawHtmlRepository $rawHtmlRepo = null,
    ): array {
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

        $snapshotRepo = $this->createMock(EqueueSnapshotRepository::class);
        $snapshotRepo->method('findLatest')->willReturn($previousSnapshot);

        $handler = $this->makeHandler(
            fetcher: $fetcher,
            em: $em,
            bus: $bus,
            snapshotRepo: $snapshotRepo,
            rawHtmlRepo: $rawHtmlRepo,
        );

        ($handler)(new PollEqueueMessage());

        return [$persisted, $dispatched];
    }

    private function makeHandler(
        ?EqueueFetcherInterface $fetcher = null,
        ?EntityManagerInterface $em = null,
        ?MessageBusInterface $bus = null,
        ?LockFactory $lockFactory = null,
        ?EqueueSnapshotRepository $snapshotRepo = null,
        ?EqueueRawHtmlRepository $rawHtmlRepo = null,
    ): PollEqueueHandler {
        $monitoring = $this->createMock(MonitoringConfigRepositoryInterface::class);
        $monitoring->method('isEnabled')->willReturn(true);

        return new PollEqueueHandler(
            fetcher: $fetcher ?? $this->createMock(EqueueFetcherInterface::class),
            rawHtmlRepository: $rawHtmlRepo ?? $this->createMock(EqueueRawHtmlRepository::class),
            entityManager: $em ?? $this->createMock(EntityManagerInterface::class),
            messageBus: $bus ?? $this->createMock(MessageBusInterface::class),
            lockFactory: $lockFactory ?? $this->lockFactory,
            snapshotRepository: $snapshotRepo ?? $this->createMock(EqueueSnapshotRepository::class),
            logger: new NullLogger(),
            monitoringConfigRepository: $monitoring,
        );
    }

    /** @param array<string, mixed> $payload */
    private function makeSnapshot(string $status, int $httpStatus, array $payload): EqueueSnapshot
    {
        return new EqueueSnapshot(
            new \DateTimeImmutable('-5 minutes'),
            $status,
            $httpStatus,
            $payload,
            0,
            'cloudflare-bypass-v1',
        );
    }
}
