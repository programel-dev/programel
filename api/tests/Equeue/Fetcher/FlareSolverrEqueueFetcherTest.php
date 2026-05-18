<?php

declare(strict_types=1);

namespace App\Tests\Equeue\Fetcher;

use App\DocumentCenter\Infrastructure\Fetcher\FlareSolverrDocumentCenterFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class FlareSolverrEqueueFetcherTest extends TestCase
{
    private const FLARESOLVERR_URL = 'http://flaresolverr:8191/v1';
    private const TARGET_URL = 'https://munich.pasport.org.ua/solutions/e-queue';

    public function testSuccessful2xxResponse(): void
    {
        $html = '<html><body>'.str_repeat('Доступні місця. ', 100).'</body></html>';

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'status' => 'ok',
            'solution' => [
                'status' => 200,
                'response' => $html,
            ],
        ]);

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $fetcher = new FlareSolverrDocumentCenterFetcher($client, self::FLARESOLVERR_URL, self::TARGET_URL, new NullLogger());
        $result = $fetcher->fetch();

        self::assertTrue($result->isSuccess());
        self::assertSame(200, $result->statusCode);
        self::assertSame($html, $result->body);
    }

    public function testFlareSolverrStatusNotOk(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'status' => 'error',
            'message' => 'Challenge not solved',
        ]);

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $fetcher = new FlareSolverrDocumentCenterFetcher($client, self::FLARESOLVERR_URL, self::TARGET_URL, new NullLogger());
        $result = $fetcher->fetch();

        self::assertFalse($result->isSuccess());
        self::assertSame(0, $result->statusCode);
        self::assertSame('', $result->body);
    }

    public function testSolutionStatusNon2xx(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'status' => 'ok',
            'solution' => [
                'status' => 403,
                'response' => '<html>Forbidden</html>',
            ],
        ]);

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $fetcher = new FlareSolverrDocumentCenterFetcher($client, self::FLARESOLVERR_URL, self::TARGET_URL, new NullLogger());
        $result = $fetcher->fetch();

        self::assertFalse($result->isSuccess());
        self::assertSame(403, $result->statusCode);
        self::assertSame('', $result->body);
    }

    public function testBlockPageReturnedAs200TreatedAsError(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'status' => 'ok',
            'solution' => [
                'status' => 200,
                'response' => '<html><body><pre>Blocked for security reasons</pre></body></html>',
            ],
        ]);

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $fetcher = new FlareSolverrDocumentCenterFetcher($client, self::FLARESOLVERR_URL, self::TARGET_URL, new NullLogger());
        $result = $fetcher->fetch();

        self::assertFalse($result->isSuccess());
        self::assertSame(0, $result->statusCode);
        self::assertSame('', $result->body);
    }

    public function testTransportExceptionReturnsStatusZero(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willThrowException(new \RuntimeException('Connection refused'));

        $fetcher = new FlareSolverrDocumentCenterFetcher($client, self::FLARESOLVERR_URL, self::TARGET_URL, new NullLogger());
        $result = $fetcher->fetch();

        self::assertFalse($result->isSuccess());
        self::assertSame(0, $result->statusCode);
        self::assertSame('', $result->body);
    }
}
