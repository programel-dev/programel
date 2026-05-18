<?php

declare(strict_types=1);

namespace App\DocumentCenter\Domain;

use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentCenterNotificationRepository::class)]
#[ORM\Table(name: 'notification', schema: 'document_center')]
#[ORM\UniqueConstraint(name: 'uniq_equeue_notification_watch_slot', columns: ['watch_id', 'slot_signature'])]
class DocumentCenterNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DocumentCenterWatch::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentCenterWatch $watch;

    #[ORM\Column(length: 64)]
    private string $slotSignature;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $telegramMessageId = null;

    public function __construct(DocumentCenterWatch $watch, string $slotSignature)
    {
        $this->watch = $watch;
        $this->slotSignature = $slotSignature;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWatch(): DocumentCenterWatch
    {
        return $this->watch;
    }

    public function getSlotSignature(): string
    {
        return $this->slotSignature;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getTelegramMessageId(): ?string
    {
        return $this->telegramMessageId;
    }

    public function setTelegramMessageId(?string $telegramMessageId): static
    {
        $this->telegramMessageId = $telegramMessageId;

        return $this;
    }
}
