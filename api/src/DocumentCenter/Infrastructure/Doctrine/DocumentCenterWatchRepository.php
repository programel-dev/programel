<?php

declare(strict_types=1);

namespace App\DocumentCenter\Infrastructure\Doctrine;

use App\DocumentCenter\Domain\DocumentCenterWatch;
use App\User\Domain\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentCenterWatch>
 */
class DocumentCenterWatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentCenterWatch::class);
    }

    /**
     * @return list<DocumentCenterWatch>
     */
    public function findAllActive(): array
    {
        /** @var list<DocumentCenterWatch> $result */
        $result = $this->createQueryBuilder('w')
            ->andWhere('w.active = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<DocumentCenterWatch>
     */
    public function findActiveForUser(User $user): array
    {
        /** @var list<DocumentCenterWatch> $result */
        $result = $this->createQueryBuilder('w')
            ->andWhere('w.user = :user')
            ->andWhere('w.active = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
