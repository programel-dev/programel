<?php

declare(strict_types=1);

namespace App\DocumentCenter\Domain;

use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterRawHtmlRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentCenterRawHtmlRepository::class)]
#[ORM\Table(name: 'raw_html', schema: 'document_center')]
#[ORM\Index(name: 'idx_equeue_raw_html_fetched_at', columns: ['fetched_at'])]
class DocumentCenterRawHtml
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column]
    private bool $alertPresent;

    #[ORM\Column(type: Types::TEXT)]
    private string $htmlBody;

    public function __construct(
        \DateTimeImmutable $fetchedAt,
        bool $alertPresent,
        string $htmlBody,
    ) {
        $this->fetchedAt = $fetchedAt;
        $this->alertPresent = $alertPresent;
        $this->htmlBody = $htmlBody;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function isAlertPresent(): bool
    {
        return $this->alertPresent;
    }

    public function getHtmlBody(): string
    {
        return $this->htmlBody;
    }
}
