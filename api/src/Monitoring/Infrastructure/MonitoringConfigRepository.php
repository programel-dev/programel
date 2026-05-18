<?php

declare(strict_types=1);

namespace App\Monitoring\Infrastructure;

use App\Monitoring\Domain\MonitoringConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoringConfig>
 */
final class MonitoringConfigRepository extends ServiceEntityRepository implements MonitoringConfigRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoringConfig::class);
    }

    public function isEnabled(): bool
    {
        return $this->find(1)?->isEnabled() ?? true;
    }

    public function getConfig(): ?MonitoringConfig
    {
        return $this->find(1);
    }

    public function getOrCreate(): MonitoringConfig
    {
        return $this->find(1) ?? new MonitoringConfig();
    }
}
