<?php

declare(strict_types=1);

namespace App\DocumentCenter\Infrastructure\Fetcher;

final readonly class DocumentCenterRawResponse
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
