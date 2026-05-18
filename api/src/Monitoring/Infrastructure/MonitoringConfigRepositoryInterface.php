<?php

declare(strict_types=1);

namespace App\Monitoring\Infrastructure;

use App\Monitoring\Domain\MonitoringConfig;

interface MonitoringConfigRepositoryInterface
{
    public function isEnabled(): bool;

    public function getConfig(): ?MonitoringConfig;

    public function getOrCreate(): MonitoringConfig;
}
