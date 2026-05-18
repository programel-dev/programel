<?php

declare(strict_types=1);

namespace App\Tests\Equeue;

use App\DocumentCenter\Domain\DocumentCenterRawHtml;
use PHPUnit\Framework\TestCase;

final class EqueueRawHtmlTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $now = new \DateTimeImmutable('2026-05-14 10:00:00');
        $entity = new DocumentCenterRawHtml($now, true, '<html>test</html>');

        self::assertNull($entity->getId());
        self::assertSame($now, $entity->getFetchedAt());
        self::assertTrue($entity->isAlertPresent());
        self::assertSame('<html>test</html>', $entity->getHtmlBody());
    }

    public function testAlertAbsent(): void
    {
        $entity = new DocumentCenterRawHtml(new \DateTimeImmutable(), false, '<html></html>');

        self::assertFalse($entity->isAlertPresent());
    }
}
