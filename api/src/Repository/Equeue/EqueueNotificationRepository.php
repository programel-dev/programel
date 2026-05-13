<?php

declare(strict_types=1);

namespace App\Repository\Equeue;

use App\Entity\Equeue\EqueueNotification;
use App\Entity\Equeue\EqueueWatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EqueueNotification>
 */
class EqueueNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EqueueNotification::class);
    }

    public function exists(EqueueWatch $watch, string $slotSignature): bool
    {
        return null !== $this->findOneBy([
            'watch' => $watch,
            'slotSignature' => $slotSignature,
        ]);
    }
}
