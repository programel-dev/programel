<?php

declare(strict_types=1);

namespace App\Tests\Equeue;

use App\DocumentCenter\Application\EvaluateWatch\EvaluateWatchMessage;
use App\DocumentCenter\Application\PollDocumentCenter\PollDocumentCenterHandler;
use App\DocumentCenter\Application\PollDocumentCenter\PollDocumentCenterMessage;
use App\DocumentCenter\Domain\DocumentCenterRawHtml;
use App\DocumentCenter\Domain\DocumentCenterSnapshot;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterRawHtmlRepository;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterSnapshotRepository;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterWatchRepository;
use App\DocumentCenter\Infrastructure\Fetcher\DocumentCenterFetcherInterface;
use App\DocumentCenter\Infrastructure\Fetcher\DocumentCenterRawResponse;
use App\Monitoring\Infrastructure\MonitoringConfigRepositoryInterface;
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

        $fetcher = $this->createMock(DocumentCenterFetcherInterface::class);
        $fetcher->expects(self::never())->method('fetch');

        $handler = $this->makeHandler(
            fetcher: $fetcher,
            lockFactory: $lockFactory,
        );

        ($handler)(new PollDocumentCenterMessage());
    }

    // --- HTTP error cases ---

    public function testFirstPollHttpErrorSilent(): void
    {
        $response = new DocumentCenterRawResponse(403, '', 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(
            response: $response,
            previousSnapshot: null,
        );

        self::assertCount(1, $persisted);
        self::assertInstanceOf(DocumentCenterSnapshot::class, $persisted[0]);
        self::assertSame(DocumentCenterSnapshot::STATUS_HTTP_ERROR, $persisted[0]->getStatus());
        self::assertSame(403, $persisted[0]->getHttpStatus());
        self::assertSame('cloudflare-bypass-v1', $persisted[0]->getParserVersion());
        self::assertCount(0, $dispatched);
    }

    public function testOkToHttpErrorSilent(): void
    {
        $previous = $this->makeSnapshot(DocumentCenterSnapshot::STATUS_OK, 200, ['alertPresent' => true]);
        $response = new DocumentCenterRawResponse(503, '', '', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        self::assertCount(1, $persisted);
        self::assertSame(DocumentCenterSnapshot::STATUS_HTTP_ERROR, $persisted[0]->getStatus());
        self::assertCount(0, $dispatched);
    }

    public function testConsecutiveHttpErrorsSilent(): void
    {
        $previous = $this->makeSnapshot(DocumentCenterSnapshot::STATUS_HTTP_ERROR, 403, []);
        $response = new DocumentCenterRawResponse(403, '', '', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        self::assertCount(1, $persisted);
        self::assertSame(DocumentCenterSnapshot::STATUS_HTTP_ERROR, $persisted[0]->getStatus());
        self::assertCount(0, $dispatched);
    }

    // --- Alert present (silence) ---

    public function testAlertPresentSavesHtmlAndSnapshotWithoutDispatch(): void
    {
        $body = '<html><div>Наразі всі місця зайняті</div></html>';
        $response = new DocumentCenterRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        $cutoffs = [];
        $rawHtmlRepo = $this->createMock(DocumentCenterRawHtmlRepository::class);
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

        $raw = array_values(array_filter($persisted, fn ($e) => $e instanceof DocumentCenterRawHtml))[0];
        self::assertTrue($raw->isAlertPresent());
        self::assertSame($body, $raw->getHtmlBody());

        $snap = array_values(array_filter($persisted, fn ($e) => $e instanceof DocumentCenterSnapshot))[0];
        self::assertSame(DocumentCenterSnapshot::STATUS_OK, $snap->getStatus());
        self::assertSame('cloudflare-bypass-v1', $snap->getParserVersion());

        self::assertCount(0, $dispatched);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $cutoffs[0]->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $cutoffs[0]->getTimestamp());
    }

    // --- Alert absent (per-watch dispatch) ---

    public function testFirstPollAlertAbsentDispatchesEvaluatePerWatch(): void
    {
        $body = '<html><p>Запис доступний</p></html>';
        $response = new DocumentCenterRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: null);

        $snap = array_values(array_filter($persisted, fn ($e) => $e instanceof DocumentCenterSnapshot))[0];
        self::assertSame('cloudflare-bypass-v1', $snap->getParserVersion());

        // 0 active watches → 0 EvaluateWatchMessage dispatched
        self::assertCount(0, $dispatched);
    }

    public function testAlertPresentToAbsentDispatchesEvaluatePerWatch(): void
    {
        $previous = $this->makeSnapshot(DocumentCenterSnapshot::STATUS_OK, 200, ['alertPresent' => true]);
        $body = '<html><p>Вільно</p></html>';
        $response = new DocumentCenterRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        // 0 active watches → 0 dispatched
        self::assertCount(0, $dispatched);
    }

    public function testConsecutiveAlertAbsentSilent(): void
    {
        $previous = $this->makeSnapshot(DocumentCenterSnapshot::STATUS_OK, 200, ['alertPresent' => false]);
        $body = '<html><p>Вільно</p></html>';
        $response = new DocumentCenterRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        self::assertCount(0, $dispatched);
    }

    public function testHttpErrorToAlertAbsentDispatchesEvaluatePerWatch(): void
    {
        $previous = $this->makeSnapshot(DocumentCenterSnapshot::STATUS_HTTP_ERROR, 403, []);
        $body = '<html><p>Вільно</p></html>';
        $response = new DocumentCenterRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        // 0 active watches → 0 dispatched
        self::assertCount(0, $dispatched);
    }

    public function testHttpErrorToAlertPresentSilent(): void
    {
        $previous = $this->makeSnapshot(DocumentCenterSnapshot::STATUS_HTTP_ERROR, 403, []);
        $body = '<html><div>Наразі всі місця зайняті</div></html>';
        $response = new DocumentCenterRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: $previous);

        self::assertCount(0, $dispatched);
    }

    // --- Playwright JSON mode ---

    public function testPlaywrightWithSlotsDispatchesEvaluatePerWatch(): void
    {
        $slots = [
            ['date' => '2026-05-25', 'times' => ['09:00', '10:30']],
            ['date' => '2026-05-26', 'times' => ['11:00']],
        ];
        $body = (string) json_encode(['success' => true, 'slots' => $slots, 'fetchedAt' => '2026-05-17T12:00:00Z']);
        $response = new DocumentCenterRawResponse(200, $body, 'application/json', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: null);

        $snap = array_values(array_filter($persisted, fn ($e) => $e instanceof DocumentCenterSnapshot))[0];
        self::assertSame('playwright-slot-v1', $snap->getParserVersion());
        self::assertFalse($snap->getPayload()['alertPresent']);
        self::assertSame($slots, $snap->getPayload()['slots']);

        // 0 active watches → 0 EvaluateWatchMessage dispatched
        self::assertCount(0, $dispatched);
    }

    public function testPlaywrightEmptySlotsAlertPresent(): void
    {
        $body = (string) json_encode(['success' => true, 'slots' => [], 'fetchedAt' => '2026-05-17T12:00:00Z']);
        $response = new DocumentCenterRawResponse(200, $body, 'application/json', new \DateTimeImmutable());

        [$persisted, $dispatched] = $this->invoke(response: $response, previousSnapshot: null);

        $snap = array_values(array_filter($persisted, fn ($e) => $e instanceof DocumentCenterSnapshot))[0];
        self::assertSame('playwright-slot-v1', $snap->getParserVersion());
        self::assertTrue($snap->getPayload()['alertPresent']);
        self::assertSame([], $snap->getPayload()['slots']);

        self::assertCount(0, $dispatched);
    }

    // --- Snapshot pruning ---

    public function testSnapshotPruningRunsOnSuccessfulPoll(): void
    {
        $body = '<html><p>Вільно</p></html>';
        $response = new DocumentCenterRawResponse(200, $body, 'text/html', new \DateTimeImmutable());

        $cutoffs = [];
        $snapshotRepo = $this->createMock(DocumentCenterSnapshotRepository::class);
        $snapshotRepo->method('findLatest')->willReturn(null);
        $snapshotRepo->expects(self::once())->method('deleteOlderThan')
            ->willReturnCallback(function (\DateTimeImmutable $cutoff) use (&$cutoffs): void {
                $cutoffs[] = $cutoff;
            });

        $before = new \DateTimeImmutable('-8 hours');
        $this->invoke(response: $response, previousSnapshot: null, snapshotRepo: $snapshotRepo);
        $after = new \DateTimeImmutable('-8 hours');

        self::assertCount(1, $cutoffs);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $cutoffs[0]->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $cutoffs[0]->getTimestamp());
    }

    public function testSnapshotPruningRunsOnHttpError(): void
    {
        $response = new DocumentCenterRawResponse(403, '', 'text/html', new \DateTimeImmutable());

        $cutoffs = [];
        $snapshotRepo = $this->createMock(DocumentCenterSnapshotRepository::class);
        $snapshotRepo->method('findLatest')->willReturn(null);
        $snapshotRepo->expects(self::once())->method('deleteOlderThan')
            ->willReturnCallback(function (\DateTimeImmutable $cutoff) use (&$cutoffs): void {
                $cutoffs[] = $cutoff;
            });

        $before = new \DateTimeImmutable('-8 hours');
        $this->invoke(response: $response, previousSnapshot: null, snapshotRepo: $snapshotRepo);
        $after = new \DateTimeImmutable('-8 hours');

        self::assertCount(1, $cutoffs);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $cutoffs[0]->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $cutoffs[0]->getTimestamp());
    }

    // --- Helpers ---

    /**
     * @return array{list<object>, list<object>}
     */
    private function invoke(
        DocumentCenterRawResponse $response,
        ?DocumentCenterSnapshot $previousSnapshot,
        ?DocumentCenterRawHtmlRepository $rawHtmlRepo = null,
        ?DocumentCenterSnapshotRepository $snapshotRepo = null,
    ): array {
        $fetcher = $this->createMock(DocumentCenterFetcherInterface::class);
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

        if (null === $snapshotRepo) {
            $snapshotRepo = $this->createMock(DocumentCenterSnapshotRepository::class);
            $snapshotRepo->method('findLatest')->willReturn($previousSnapshot);
        }

        $handler = $this->makeHandler(
            fetcher: $fetcher,
            em: $em,
            bus: $bus,
            snapshotRepo: $snapshotRepo,
            rawHtmlRepo: $rawHtmlRepo,
        );

        ($handler)(new PollDocumentCenterMessage());

        return [$persisted, $dispatched];
    }

    private function makeHandler(
        ?DocumentCenterFetcherInterface $fetcher = null,
        ?EntityManagerInterface $em = null,
        ?MessageBusInterface $bus = null,
        ?LockFactory $lockFactory = null,
        ?DocumentCenterSnapshotRepository $snapshotRepo = null,
        ?DocumentCenterRawHtmlRepository $rawHtmlRepo = null,
        ?DocumentCenterWatchRepository $watchRepo = null,
    ): PollDocumentCenterHandler {
        $monitoring = $this->createMock(MonitoringConfigRepositoryInterface::class);
        $monitoring->method('isEnabled')->willReturn(true);

        return new PollDocumentCenterHandler(
            fetcher: $fetcher ?? $this->createMock(DocumentCenterFetcherInterface::class),
            rawHtmlRepository: $rawHtmlRepo ?? $this->createMock(DocumentCenterRawHtmlRepository::class),
            entityManager: $em ?? $this->createMock(EntityManagerInterface::class),
            messageBus: $bus ?? $this->createMock(MessageBusInterface::class),
            lockFactory: $lockFactory ?? $this->lockFactory,
            snapshotRepository: $snapshotRepo ?? $this->createMock(DocumentCenterSnapshotRepository::class),
            watchRepository: $watchRepo ?? $this->createConfiguredMock(DocumentCenterWatchRepository::class, ['findAllActive' => []]),
            logger: new NullLogger(),
            monitoringConfigRepository: $monitoring,
        );
    }

    /** @param array<string, mixed> $payload */
    private function makeSnapshot(string $status, int $httpStatus, array $payload): DocumentCenterSnapshot
    {
        return new DocumentCenterSnapshot(
            new \DateTimeImmutable('-5 minutes'),
            $status,
            $httpStatus,
            $payload,
            0,
            'cloudflare-bypass-v1',
        );
    }
}
