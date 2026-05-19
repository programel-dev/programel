<?php

declare(strict_types=1);

namespace App\DocumentCenter\Application\BroadcastSlotsAvailable;

final class BroadcastSlotsAvailableMessage
{
    /** @param list<string> $slots */
    public function __construct(
        public readonly ?string $date = null,
        public readonly array $slots = [],
    ) {
    }
}
