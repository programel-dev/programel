<?php

declare(strict_types=1);

namespace App\Repository;

interface MonitoringConfigRepositoryInterface
{
    public function isEnabled(): bool;
}
