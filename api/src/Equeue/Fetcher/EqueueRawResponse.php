<?php

declare(strict_types=1);

namespace App\Equeue\Fetcher;

final readonly class EqueueRawResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
        public string $contentType,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
