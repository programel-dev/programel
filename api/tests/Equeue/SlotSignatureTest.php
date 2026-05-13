<?php

declare(strict_types=1);

namespace App\Tests\Equeue;

use App\Equeue\SlotSignature;
use PHPUnit\Framework\TestCase;

final class SlotSignatureTest extends TestCase
{
    public function testProducesDeterministicHash(): void
    {
        $slotAt = new \DateTimeImmutable('2026-06-01T10:30:00+00:00');

        $first = SlotSignature::for('passport', $slotAt);
        $second = SlotSignature::for('passport', $slotAt);

        self::assertSame($first, $second);
        self::assertSame(64, strlen($first));
    }

    public function testDifferentServiceProducesDifferentHash(): void
    {
        $slotAt = new \DateTimeImmutable('2026-06-01T10:30:00+00:00');

        $passport = SlotSignature::for('passport', $slotAt);
        $notary = SlotSignature::for('notary', $slotAt);

        self::assertNotSame($passport, $notary);
    }

    public function testDifferentTimeProducesDifferentHash(): void
    {
        $first = SlotSignature::for('passport', new \DateTimeImmutable('2026-06-01T10:30:00+00:00'));
        $second = SlotSignature::for('passport', new \DateTimeImmutable('2026-06-01T11:00:00+00:00'));

        self::assertNotSame($first, $second);
    }
}
