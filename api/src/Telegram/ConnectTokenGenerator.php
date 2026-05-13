<?php

declare(strict_types=1);

namespace App\Telegram;

final class ConnectTokenGenerator
{
    private const TOKEN_BYTES = 24;
    public const TOKEN_TTL_SECONDS = 900;

    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    public function expiresAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('+%d seconds', self::TOKEN_TTL_SECONDS));
    }
}
