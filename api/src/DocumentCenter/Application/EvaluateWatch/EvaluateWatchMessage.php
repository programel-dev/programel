<?php

declare(strict_types=1);

namespace App\Message\Equeue;

final readonly class EvaluateWatchMessage
{
    public function __construct(
        public int $watchId,
        public int $snapshotId,
    ) {
    }
}
