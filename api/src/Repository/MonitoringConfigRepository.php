<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoringConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoringConfig>
 */
final class MonitoringConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoringConfig::class);
    }

    public function isEnabled(): bool
    {
        return $this->find(1)?->isEnabled() ?? true;
    }

    public function getOrCreate(): MonitoringConfig
    {
        return $this->find(1) ?? new MonitoringConfig();
    }
}
