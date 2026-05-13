<?php

declare(strict_types=1);

namespace App\Entity\Equeue;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\User;
use App\Repository\Equeue\EqueueWatchRepository;
use App\State\EqueueWatchOwnerProcessor;
use App\State\EqueueWatchOwnerProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EqueueWatchRepository::class)]
#[ORM\Table(name: 'equeue_watch')]
#[ORM\Index(name: 'idx_equeue_watch_user_active', columns: ['user_id', 'active'])]
#[ApiResource(
    shortName: 'EqueueWatch',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            provider: EqueueWatchOwnerProvider::class,
        ),
        new Get(security: "is_granted('ROLE_USER') and object.getUser() == user"),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: EqueueWatchOwnerProcessor::class,
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and object.getUser() == user",
            processor: EqueueWatchOwnerProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_USER') and object.getUser() == user"),
    ],
    normalizationContext: ['groups' => ['equeue_watch:read']],
    denormalizationContext: ['groups' => ['equeue_watch:write']],
)]
class EqueueWatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['equeue_watch:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    #[Groups(['equeue_watch:read', 'equeue_watch:write'])]
    private string $serviceCode = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['equeue_watch:read', 'equeue_watch:write'])]
    private ?string $serviceLabel = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    #[Groups(['equeue_watch:read', 'equeue_watch:write'])]
    private ?\DateTimeImmutable $dateFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\Expression(
        'this.getDateFrom() === null or this.getDateTo() === null or this.getDateFrom() <= this.getDateTo()',
        message: 'dateFrom must be on or before dateTo'
    )]
    #[Groups(['equeue_watch:read', 'equeue_watch:write'])]
    private ?\DateTimeImmutable $dateTo = null;

    #[ORM\Column]
    #[Groups(['equeue_watch:read', 'equeue_watch:write'])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['equeue_watch:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['equeue_watch:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getServiceCode(): string
    {
        return $this->serviceCode;
    }

    public function setServiceCode(string $serviceCode): static
    {
        $this->serviceCode = $serviceCode;
        $this->touch();

        return $this;
    }

    public function getServiceLabel(): ?string
    {
        return $this->serviceLabel;
    }

    public function setServiceLabel(?string $serviceLabel): static
    {
        $this->serviceLabel = $serviceLabel;
        $this->touch();

        return $this;
    }

    public function getDateFrom(): ?\DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function setDateFrom(\DateTimeImmutable $dateFrom): static
    {
        $this->dateFrom = $dateFrom;
        $this->touch();

        return $this;
    }

    public function getDateTo(): ?\DateTimeImmutable
    {
        return $this->dateTo;
    }

    public function setDateTo(\DateTimeImmutable $dateTo): static
    {
        $this->dateTo = $dateTo;
        $this->touch();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
