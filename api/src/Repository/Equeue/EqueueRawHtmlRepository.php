<?php

declare(strict_types=1);

namespace App\Repository\Equeue;

use App\Entity\Equeue\EqueueRawHtml;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EqueueRawHtml>
 */
class EqueueRawHtmlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EqueueRawHtml::class);
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.fetchedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
