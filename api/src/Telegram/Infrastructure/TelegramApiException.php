<?php

declare(strict_types=1);

namespace App\Telegram;

class TelegramApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly bool $retryable,
    ) {
        parent::__construct($message);
    }
}
