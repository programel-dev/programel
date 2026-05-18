<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoringConfig;

interface MonitoringConfigRepositoryInterface
{
    public function isEnabled(): bool;

    public function getConfig(): ?MonitoringConfig;

    public function getOrCreate(): MonitoringConfig;
}
