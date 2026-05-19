<?php

declare(strict_types=1);

namespace App\Tests\Behat\Fake;

use App\DocumentCenter\Infrastructure\Fetcher\DocumentCenterFetcherInterface;
use App\DocumentCenter\Infrastructure\Fetcher\DocumentCenterRawResponse;

final class FakeDocumentCenterFetcher implements DocumentCenterFetcherInterface
{
    private ?DocumentCenterRawResponse $response = null;

    public function setResponse(DocumentCenterRawResponse $response): void
    {
        $this->response = $response;
    }

    public function fetch(): DocumentCenterRawResponse
    {
        if (null === $this->response) {
            throw new \LogicException('FakeDocumentCenterFetcher: no response configured for this scenario');
        }

        return $this->response;
    }
}
