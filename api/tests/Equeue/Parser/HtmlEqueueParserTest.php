<?php

declare(strict_types=1);

namespace App\Tests\Equeue\Parser;

use App\Equeue\Fetcher\EqueueRawResponse;
use App\Equeue\Parser\EqueueParseException;
use App\Equeue\Parser\HtmlEqueueParser;
use PHPUnit\Framework\TestCase;

final class HtmlEqueueParserTest extends TestCase
{
    public function testParsesServicesAndSlotsFromSamplePage(): void
    {
        $parser = new HtmlEqueueParser();
        $response = $this->buildResponse(__DIR__.'/../Fixtures/sample-page.html');

        $data = $parser->parse($response);

        self::assertCount(2, $data->services);
        self::assertSame('passport', $data->services[0]->code);
        self::assertSame('Закордонний паспорт', $data->services[0]->label);

        self::assertCount(3, $data->slots);
        $first = $data->slots[0];
        self::assertSame('passport', $first->serviceCode);
        self::assertSame('2026-06-15T09:30:00+02:00', $first->slotAt->format(\DateTimeInterface::ATOM));
        self::assertSame('abc123', $first->reference);
    }

    public function testEmptyPageReturnsNoSlots(): void
    {
        $parser = new HtmlEqueueParser();
        $response = $this->buildResponse(__DIR__.'/../Fixtures/sample-empty.html');

        $data = $parser->parse($response);

        self::assertSame([], $data->services);
        self::assertSame([], $data->slots);
    }

    public function testEmptyBodyThrows(): void
    {
        $parser = new HtmlEqueueParser();
        $response = new EqueueRawResponse(200, '', 'text/html', new \DateTimeImmutable());

        $this->expectException(EqueueParseException::class);
        $parser->parse($response);
    }

    public function testVersionIsStable(): void
    {
        self::assertSame('html-v1', (new HtmlEqueueParser())->version());
    }

    private function buildResponse(string $fixturePath): EqueueRawResponse
    {
        $body = file_get_contents($fixturePath);
        self::assertIsString($body);

        return new EqueueRawResponse(200, $body, 'text/html; charset=utf-8', new \DateTimeImmutable());
    }
}
