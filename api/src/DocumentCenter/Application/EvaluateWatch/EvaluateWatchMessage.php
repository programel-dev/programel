<?php

declare(strict_types=1);

namespace App\DocumentCenter\Application\EvaluateWatch;

final readonly class EvaluateWatchMessage
{
    public function __construct(
        public int $watchId,
        public int $snapshotId,
    ) {
    }
}
