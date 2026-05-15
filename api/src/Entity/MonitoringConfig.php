<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitoringConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitoringConfigRepository::class)]
#[ORM\Table(name: 'monitoring_config')]
class MonitoringConfig
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setEnabled(bool $enabled, User $updatedBy): static
    {
        $this->enabled = $enabled;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
