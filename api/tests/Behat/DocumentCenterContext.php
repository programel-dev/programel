<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\DocumentCenter\Application\BroadcastSlotsAvailable\BroadcastSlotsAvailableMessage;
use App\DocumentCenter\Application\PollDocumentCenter\PollDocumentCenterHandler;
use App\DocumentCenter\Application\PollDocumentCenter\PollDocumentCenterMessage;
use App\DocumentCenter\Domain\DocumentCenterRawHtml;
use App\DocumentCenter\Domain\DocumentCenterSnapshot;
use App\DocumentCenter\Infrastructure\Fetcher\DocumentCenterRawResponse;
use App\Tests\Behat\Fake\FakeDocumentCenterFetcher;
use Behat\Behat\Context\Context;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class DocumentCenterContext implements Context
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FakeDocumentCenterFetcher $fetcher,
        private readonly PollDocumentCenterHandler $handler,
        private readonly InMemoryTransport $asyncTransport,
    ) {
    }

    /**
     * @BeforeScenario
     */
    public function resetState(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.DocumentCenterRawHtml::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.DocumentCenterSnapshot::class)->execute();
        $this->entityManager->createQuery('DELETE FROM App\Monitoring\Domain\MonitoringConfig m')->execute();
        $this->entityManager->clear();
        $this->asyncTransport->reset();
        $this->fetcher->setResponse(
            new DocumentCenterRawResponse(200, '<html><p>Запис доступний</p></html>', 'text/html', new \DateTimeImmutable())
        );
    }

    /**
     * @Given the fetcher will return an HTML response without alert
     */
    public function theFetcherWillReturnHtmlResponseWithoutAlert(): void
    {
        $body = '<html><p>Запис доступний</p></html>';
        $this->fetcher->setResponse(new DocumentCenterRawResponse(200, $body, 'text/html', new \DateTimeImmutable()));
    }

    /**
     * @Given the fetcher will return an HTML response with alert
     */
    public function theFetcherWillReturnHtmlResponseWithAlert(): void
    {
        $body = '<html><div>Наразі всі місця зайняті</div></html>';
        $this->fetcher->setResponse(new DocumentCenterRawResponse(200, $body, 'text/html', new \DateTimeImmutable()));
    }

    /**
     * @Given the fetcher will return an HTTP error with status :code
     */
    public function theFetcherWillReturnHttpError(int $code): void
    {
        $this->fetcher->setResponse(new DocumentCenterRawResponse($code, '', 'text/html', new \DateTimeImmutable()));
    }

    /**
     * @Given the previous snapshot had alertPresent :value
     */
    public function thePreviousSnapshotHadAlertPresent(string $value): void
    {
        $alertPresent = 'true' === $value;
        $this->entityManager->persist(new DocumentCenterSnapshot(
            new \DateTimeImmutable('-5 minutes'),
            DocumentCenterSnapshot::STATUS_OK,
            200,
            ['alertPresent' => $alertPresent, 'slots' => []],
            0,
            'cloudflare-bypass-v1',
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * @Given there is a raw html row older than 8 hours
     */
    public function thereIsAnOldRawHtmlRow(): void
    {
        $this->entityManager->persist(new DocumentCenterRawHtml(new \DateTimeImmutable('-9 hours'), false, '<html>old</html>'));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * @When the poll document center handler runs
     */
    public function thePollDocumentCenterHandlerRuns(): void
    {
        ($this->handler)(new PollDocumentCenterMessage());
        $this->entityManager->clear();
    }

    /**
     * @Then the raw html table should have :count rows
     */
    public function theRawHtmlTableShouldHaveRows(int $count): void
    {
        $actual = $this->entityManager->getRepository(DocumentCenterRawHtml::class)->count([]);
        if ($actual !== $count) {
            throw new \RuntimeException(sprintf('Expected %d row(s) in document_center.raw_html, got %d', $count, $actual));
        }
    }

    /**
     * @Then the snapshot table should have :count rows
     */
    public function theSnapshotTableShouldHaveRows(int $count): void
    {
        $actual = $this->entityManager->getRepository(DocumentCenterSnapshot::class)->count([]);
        if ($actual !== $count) {
            throw new \RuntimeException(sprintf('Expected %d row(s) in document_center.snapshot, got %d', $count, $actual));
        }
    }

    /**
     * @Then the snapshot parser version should be :version
     */
    public function theSnapshotParserVersionShouldBe(string $version): void
    {
        $snapshot = $this->entityManager->getRepository(DocumentCenterSnapshot::class)->findOneBy([], ['id' => 'DESC']);
        if (null === $snapshot) {
            throw new \RuntimeException('No row found in document_center.snapshot');
        }
        if ($snapshot->getParserVersion() !== $version) {
            throw new \RuntimeException(sprintf('Expected parser version "%s", got "%s"', $version, $snapshot->getParserVersion()));
        }
    }

    /**
     * @Then the snapshot status should be :status
     */
    public function theSnapshotStatusShouldBe(string $status): void
    {
        $snapshot = $this->entityManager->getRepository(DocumentCenterSnapshot::class)->findOneBy([]);
        if (null === $snapshot) {
            throw new \RuntimeException('No row found in document_center.snapshot');
        }
        if ($snapshot->getStatus() !== $status) {
            throw new \RuntimeException(sprintf('Expected snapshot status "%s", got "%s"', $status, $snapshot->getStatus()));
        }
    }

    /**
     * @Then the snapshot payload alertPresent should be :value
     */
    public function theSnapshotPayloadAlertPresentShouldBe(string $value): void
    {
        $snapshot = $this->entityManager->getRepository(DocumentCenterSnapshot::class)->findOneBy([], ['id' => 'DESC']);
        if (null === $snapshot) {
            throw new \RuntimeException('No row found in document_center.snapshot');
        }
        $expected = 'true' === $value;
        $actual = $snapshot->getPayload()['alertPresent'] ?? null;
        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf('Expected snapshot payload alertPresent to be %s, got %s', var_export($expected, true), var_export($actual, true)));
        }
    }

    /**
     * @Then the raw html row alertPresent should be :value
     */
    public function theRawHtmlRowAlertPresentShouldBe(string $value): void
    {
        $rawHtml = $this->entityManager->getRepository(DocumentCenterRawHtml::class)->findOneBy([]);
        if (null === $rawHtml) {
            throw new \RuntimeException('No row found in document_center.raw_html');
        }
        $expected = 'true' === $value;
        if ($rawHtml->isAlertPresent() !== $expected) {
            throw new \RuntimeException(sprintf('Expected raw_html alertPresent to be %s, got %s', var_export($expected, true), var_export($rawHtml->isAlertPresent(), true)));
        }
    }

    /**
     * @Then a broadcast slots available message should be dispatched
     */
    public function aBroadcastSlotsAvailableMessageShouldBeDispatched(): void
    {
        foreach ($this->asyncTransport->get() as $envelope) {
            if ($envelope->getMessage() instanceof BroadcastSlotsAvailableMessage) {
                return;
            }
        }
        throw new \RuntimeException('Expected BroadcastSlotsAvailableMessage in async transport, but none found');
    }

    /**
     * @Then no broadcast slots available message should be dispatched
     */
    public function noBroadcastSlotsAvailableMessageShouldBeDispatched(): void
    {
        foreach ($this->asyncTransport->get() as $envelope) {
            if ($envelope->getMessage() instanceof BroadcastSlotsAvailableMessage) {
                throw new \RuntimeException('Expected no BroadcastSlotsAvailableMessage in async transport, but one was found');
            }
        }
    }
}
