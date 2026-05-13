<?php

declare(strict_types=1);

namespace App\Equeue\Fetcher;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpEqueueFetcher implements EqueueFetcherInterface
{
    public function __construct(
        private readonly HttpClientInterface $equeueClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetch(): EqueueRawResponse
    {
        $fetchedAt = new \DateTimeImmutable();

        try {
            $response = $this->equeueClient->request('GET', '');
            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
        } catch (HttpExceptionInterface $exception) {
            $statusCode = $exception->getResponse()->getStatusCode();
            $body = '';
            $contentType = '';
            $this->logger->warning('e-queue fetch returned HTTP error', [
                'status' => $statusCode,
                'exception' => $exception->getMessage(),
            ]);
        } catch (TransportException $exception) {
            $statusCode = 0;
            $body = '';
            $contentType = '';
            $this->logger->error('e-queue fetch transport failure', [
                'exception' => $exception->getMessage(),
            ]);
        }

        return new EqueueRawResponse($statusCode, $body, $contentType, $fetchedAt);
    }
}
