<?php

declare(strict_types=1);

namespace App\DocumentCenter\Infrastructure\Fetcher;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlaywrightDocumentCenterFetcher implements DocumentCenterFetcherInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $playwrightUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetch(): DocumentCenterRawResponse
    {
        $fetchedAt = new \DateTimeImmutable();

        if ('' === $this->playwrightUrl) {
            $this->logger->error('playwright fetcher: PLAYWRIGHT_EQUEUE_URL not configured');

            return new DocumentCenterRawResponse(0, '', '', $fetchedAt);
        }

        try {
            $response = $this->httpClient->request('GET', $this->playwrightUrl.'/slots', [
                'timeout' => 60,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('playwright fetcher request failed', ['exception' => $e->getMessage()]);

            return new DocumentCenterRawResponse(0, '', '', $fetchedAt);
        }

        if (!($data['success'] ?? false)) {
            $this->logger->warning('playwright scraper returned failure', [
                'reason' => $data['reason'] ?? 'unknown',
            ]);

            return new DocumentCenterRawResponse(0, '', '', $fetchedAt);
        }

        return new DocumentCenterRawResponse(200, (string) json_encode($data), 'application/json', $fetchedAt);
    }
}
