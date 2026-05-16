<?php

declare(strict_types=1);

namespace App\Repository\Equeue;

use App\Entity\Equeue\EqueueSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EqueueSnapshot>
 */
class EqueueSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EqueueSnapshot::class);
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.fetchedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    public function findLatest(): ?EqueueSnapshot
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.fetchedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
