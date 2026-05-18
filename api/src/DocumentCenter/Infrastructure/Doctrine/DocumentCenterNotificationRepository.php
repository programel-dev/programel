<?php

declare(strict_types=1);

namespace App\DocumentCenter\Infrastructure\Doctrine;

use App\DocumentCenter\Domain\DocumentCenterNotification;
use App\DocumentCenter\Domain\DocumentCenterWatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentCenterNotification>
 */
class DocumentCenterNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentCenterNotification::class);
    }

    public function exists(DocumentCenterWatch $watch, string $slotSignature): bool
    {
        return null !== $this->findOneBy([
            'watch' => $watch,
            'slotSignature' => $slotSignature,
        ]);
    }
}
