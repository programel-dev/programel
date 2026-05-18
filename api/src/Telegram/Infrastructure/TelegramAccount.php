<?php

declare(strict_types=1);

namespace App\Entity\Telegram;

use App\Entity\User;
use App\Repository\Telegram\TelegramAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TelegramAccountRepository::class)]
#[ORM\Table(name: 'telegram_account')]
#[ORM\Index(name: 'idx_telegram_account_connect_token', columns: ['connect_token'])]
class TelegramAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $chatId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $connectToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $connectedAt = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getChatId(): ?string
    {
        return $this->chatId;
    }

    public function setChatId(?string $chatId): static
    {
        $this->chatId = $chatId;

        return $this;
    }

    public function isConnected(): bool
    {
        return null !== $this->chatId;
    }

    public function getConnectToken(): ?string
    {
        return $this->connectToken;
    }

    public function getTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function issueToken(string $token, \DateTimeImmutable $expiresAt): static
    {
        $this->connectToken = $token;
        $this->tokenExpiresAt = $expiresAt;

        return $this;
    }

    public function isTokenValid(\DateTimeImmutable $now): bool
    {
        return null !== $this->connectToken
            && null !== $this->tokenExpiresAt
            && $this->tokenExpiresAt > $now;
    }

    public function bind(string $chatId, \DateTimeImmutable $now): static
    {
        $this->chatId = $chatId;
        $this->connectedAt = $now;
        $this->connectToken = null;
        $this->tokenExpiresAt = null;

        return $this;
    }

    public function getConnectedAt(): ?\DateTimeImmutable
    {
        return $this->connectedAt;
    }
}
