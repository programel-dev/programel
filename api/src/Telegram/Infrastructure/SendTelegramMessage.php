<?php

declare(strict_types=1);

namespace App\Telegram\Infrastructure;

final readonly class SendTelegramMessage
{
    public function __construct(
        public string $chatId,
        public string $text,
        public ?int $notificationId = null,
    ) {
    }
}
