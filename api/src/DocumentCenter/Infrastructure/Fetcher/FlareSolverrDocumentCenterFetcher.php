<?php

declare(strict_types=1);

namespace App\DocumentCenter\Infrastructure\Fetcher;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FlareSolverrDocumentCenterFetcher implements DocumentCenterFetcherInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $flaresolverrUrl,
        private readonly string $targetUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetch(): DocumentCenterRawResponse
    {
        $fetchedAt = new \DateTimeImmutable();

        $result = $this->attemptFetch($fetchedAt);

        if (!$result->isSuccess()) {
            $this->logger->info('flaresolverr fetch failed, retrying once');
            $result = $this->attemptFetch($fetchedAt);
        }

        return $result;
    }

    private function attemptFetch(\DateTimeImmutable $fetchedAt): DocumentCenterRawResponse
    {
        try {
            $response = $this->httpClient->request('POST', $this->flaresolverrUrl, [
                'json' => [
                    'cmd' => 'request.get',
                    'url' => $this->targetUrl,
                    'maxTimeout' => 30000,
                ],
                'timeout' => 50,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('flaresolverr request failed', ['exception' => $e->getMessage()]);

            return new DocumentCenterRawResponse(0, '', '', $fetchedAt);
        }

        if (($data['status'] ?? '') !== 'ok') {
            $this->logger->warning('flaresolverr returned non-ok status', [
                'status' => $data['status'] ?? 'unknown',
                'message' => $data['message'] ?? '',
            ]);

            return new DocumentCenterRawResponse(0, '', '', $fetchedAt);
        }

        $httpStatus = (int) ($data['solution']['status'] ?? 0);

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $this->logger->warning('e-queue page returned non-2xx via flaresolverr', ['status' => $httpStatus]);

            return new DocumentCenterRawResponse($httpStatus, '', '', $fetchedAt);
        }

        $body = $data['solution']['response'] ?? '';

        if (strlen($body) < 1000) {
            $this->logger->warning('e-queue response suspiciously small, likely a block page', [
                'bytes' => strlen($body),
                'preview' => substr($body, 0, 200),
            ]);

            return new DocumentCenterRawResponse(0, '', '', $fetchedAt);
        }

        return new DocumentCenterRawResponse($httpStatus, $body, 'text/html', $fetchedAt);
    }
}
