<?php

declare(strict_types=1);

namespace App\Equeue\Fetcher;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FlareSolverrEqueueFetcher implements EqueueFetcherInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $flaresolverrUrl,
        private readonly string $targetUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetch(): EqueueRawResponse
    {
        $fetchedAt = new \DateTimeImmutable();

        try {
            $response = $this->httpClient->request('POST', $this->flaresolverrUrl, [
                'json' => [
                    'cmd' => 'request.get',
                    'url' => $this->targetUrl,
                    'maxTimeout' => 30000,
                ],
                'timeout' => 35,
            ]);

            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('flaresolverr request failed', ['exception' => $e->getMessage()]);

            return new EqueueRawResponse(0, '', '', $fetchedAt);
        }

        if (($data['status'] ?? '') !== 'ok') {
            $this->logger->warning('flaresolverr returned non-ok status', [
                'status' => $data['status'] ?? 'unknown',
                'message' => $data['message'] ?? '',
            ]);

            return new EqueueRawResponse(0, '', '', $fetchedAt);
        }

        $httpStatus = (int) ($data['solution']['status'] ?? 0);

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $this->logger->warning('e-queue page returned non-2xx via flaresolverr', ['status' => $httpStatus]);

            return new EqueueRawResponse($httpStatus, '', '', $fetchedAt);
        }

        return new EqueueRawResponse($httpStatus, $data['solution']['response'] ?? '', 'text/html', $fetchedAt);
    }
}
