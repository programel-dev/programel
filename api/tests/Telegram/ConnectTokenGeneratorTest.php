<?php

declare(strict_types=1);

namespace App\Tests\Telegram;

use App\Telegram\Infrastructure\ConnectTokenGenerator;
use PHPUnit\Framework\TestCase;

final class ConnectTokenGeneratorTest extends TestCase
{
    public function testGeneratedTokenIsUrlSafeAndUnique(): void
    {
        $generator = new ConnectTokenGenerator();
        $tokens = [];
        for ($i = 0; $i < 10; ++$i) {
            $token = $generator->generate();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+$/', $token);
            self::assertGreaterThanOrEqual(20, strlen($token));
            $tokens[] = $token;
        }

        self::assertCount(10, array_unique($tokens));
    }

    public function testExpiresAtIs15MinutesFromNow(): void
    {
        $generator = new ConnectTokenGenerator();
        $now = new \DateTimeImmutable('2026-05-13T12:00:00+00:00');

        $expiresAt = $generator->expiresAt($now);

        self::assertSame(
            $now->modify('+900 seconds')->format(\DateTimeInterface::ATOM),
            $expiresAt->format(\DateTimeInterface::ATOM),
        );
    }
}
