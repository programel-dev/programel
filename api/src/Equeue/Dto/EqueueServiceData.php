<?php

declare(strict_types=1);

namespace App\Equeue\Dto;

final readonly class EqueueServiceData
{
    public function __construct(
        public string $code,
        public string $label,
    ) {
    }
}
