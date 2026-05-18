<?php

declare(strict_types=1);

namespace App\Entity\Equeue;

use App\Repository\Equeue\EqueueSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EqueueSnapshotRepository::class)]
#[ORM\Table(name: 'equeue_snapshot')]
#[ORM\Index(name: 'idx_equeue_snapshot_fetched_at', columns: ['fetched_at'])]
class EqueueSnapshot
{
    public const STATUS_OK = 'ok';
    public const STATUS_HTTP_ERROR = 'http_error';
    public const STATUS_PARSE_ERROR = 'parse_error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column]
    private int $httpStatus;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column]
    private int $slotCount = 0;

    #[ORM\Column(length: 32)]
    private string $parserVersion;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        \DateTimeImmutable $fetchedAt,
        string $status,
        int $httpStatus,
        array $payload,
        int $slotCount,
        string $parserVersion,
    ) {
        $this->fetchedAt = $fetchedAt;
        $this->status = $status;
        $this->httpStatus = $httpStatus;
        $this->payload = $payload;
        $this->slotCount = $slotCount;
        $this->parserVersion = $parserVersion;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getSlotCount(): int
    {
        return $this->slotCount;
    }

    public function getParserVersion(): string
    {
        return $this->parserVersion;
    }
}
