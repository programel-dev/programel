<?php

declare(strict_types=1);

namespace App\DocumentCenter\Infrastructure\Doctrine;

use App\DocumentCenter\Domain\DocumentCenterSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentCenterSnapshot>
 */
class DocumentCenterSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentCenterSnapshot::class);
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

    public function findLatest(): ?DocumentCenterSnapshot
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.fetchedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
