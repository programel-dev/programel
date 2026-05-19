<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\DocumentCenter\Application\PollDocumentCenter\PollDocumentCenterHandler;
use App\DocumentCenter\Application\PollDocumentCenter\PollDocumentCenterMessage;
use App\DocumentCenter\Domain\DocumentCenterRawHtml;
use App\DocumentCenter\Domain\DocumentCenterSlot;
use App\DocumentCenter\Domain\DocumentCenterSnapshot;
use App\DocumentCenter\Infrastructure\Fetcher\DocumentCenterRawResponse;
use App\Tests\Behat\Fake\FakeDocumentCenterFetcher;
use Behat\Behat\Context\Context;
use Doctrine\ORM\EntityManagerInterface;

final class DocumentCenterContext implements Context
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FakeDocumentCenterFetcher $fetcher,
        private readonly PollDocumentCenterHandler $handler,
    ) {
    }

    /**
     * @BeforeScenario
     */
    public function resetState(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.DocumentCenterSlot::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.DocumentCenterRawHtml::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.DocumentCenterSnapshot::class)->execute();
        $this->entityManager->createQuery('DELETE FROM App\Monitoring\Domain\MonitoringConfig m')->execute();
        $this->entityManager->clear();
        $this->fetcher->setResponse(
            new DocumentCenterRawResponse(200, '{}', 'application/json', new \DateTimeImmutable())
        );
    }

    /**
     * @Given the fetcher will return a Playwright JSON response with :count slots
     */
    public function theFetcherWillReturnPlaywrightResponse(int $count): void
    {
        $slots = [];
        for ($i = 0; $i < $count; ++$i) {
            $slots[] = ['date' => sprintf('2026-05-%02d', 25 + $i), 'times' => ['09:00', '10:30']];
        }
        $body = (string) json_encode(['success' => true, 'slots' => $slots]);
        $this->fetcher->setResponse(new DocumentCenterRawResponse(200, $body, 'application/json', new \DateTimeImmutable()));
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
     * @Given there is a slot row older than 8 hours
     */
    public function thereIsAnOldSlotRow(): void
    {
        $this->entityManager->persist(new DocumentCenterSlot(new \DateTimeImmutable('-9 hours'), []));
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
     * @Then the slot table should have :count rows
     */
    public function theSlotTableShouldHaveRows(int $count): void
    {
        $actual = $this->entityManager->getRepository(DocumentCenterSlot::class)->count([]);
        if ($actual !== $count) {
            throw new \RuntimeException(sprintf('Expected %d row(s) in document_center.slot, got %d', $count, $actual));
        }
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
     * @Then the slot row should have :count slots
     */
    public function theSlotRowShouldHaveSlots(int $count): void
    {
        $slot = $this->entityManager->getRepository(DocumentCenterSlot::class)->findOneBy([]);
        if (null === $slot) {
            throw new \RuntimeException('No row found in document_center.slot');
        }
        $actual = count($slot->getSlots());
        if ($actual !== $count) {
            throw new \RuntimeException(sprintf('Expected %d slot(s) in slot row, got %d', $count, $actual));
        }
    }

    /**
     * @Then the snapshot parser version should be :version
     */
    public function theSnapshotParserVersionShouldBe(string $version): void
    {
        $snapshot = $this->entityManager->getRepository(DocumentCenterSnapshot::class)->findOneBy([]);
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
        $snapshot = $this->entityManager->getRepository(DocumentCenterSnapshot::class)->findOneBy([]);
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
}
