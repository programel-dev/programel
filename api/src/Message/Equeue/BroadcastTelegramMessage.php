<?php

declare(strict_types=1);

namespace App\Message\Equeue;

final readonly class BroadcastTelegramMessage
{
    public function __construct(
        public string $text,
    ) {
    }
}
