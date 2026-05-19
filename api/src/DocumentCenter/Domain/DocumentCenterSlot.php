<?php

declare(strict_types=1);

namespace App\DocumentCenter\Domain;

use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterSlotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentCenterSlotRepository::class)]
#[ORM\Table(name: 'slot', schema: 'document_center')]
#[ORM\Index(name: 'idx_document_center_slot_fetched_at', columns: ['fetched_at'])]
class DocumentCenterSlot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column(type: Types::JSON)]
    private array $slots;

    public function __construct(
        \DateTimeImmutable $fetchedAt,
        array $slots,
    ) {
        $this->fetchedAt = $fetchedAt;
        $this->slots = $slots;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    /** @return list<array{date: string, times: list<string>}> */
    public function getSlots(): array
    {
        return $this->slots;
    }
}
