<?php

declare(strict_types=1);

namespace App\Tests\Behat\Fake;

use App\DocumentCenter\Infrastructure\Fetcher\DocumentCenterFetcherInterface;
use App\DocumentCenter\Infrastructure\Fetcher\DocumentCenterRawResponse;

final class FakeSlotScraper implements DocumentCenterFetcherInterface
{
    private ?DocumentCenterRawResponse $response = null;

    public function setResponse(DocumentCenterRawResponse $response): void
    {
        $this->response = $response;
    }

    public function fetch(): DocumentCenterRawResponse
    {
        return $this->response ?? new DocumentCenterRawResponse(
            0,
            '',
            'application/json',
            new \DateTimeImmutable(),
        );
    }
}
