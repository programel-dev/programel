<?php

declare(strict_types=1);

namespace App\DocumentCenter\Application\Event;

final readonly class DocumentCenterSlotAvailableEvent
{
    public function __construct(
        public int $userId,
        public \DateTimeImmutable $slotAt,
        public string $serviceCode,
        public string $serviceLabel,
        public int $notificationId,
    ) {
    }
}
