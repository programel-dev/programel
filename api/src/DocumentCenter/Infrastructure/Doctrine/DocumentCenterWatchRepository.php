<?php

declare(strict_types=1);

namespace App\Repository\Equeue;

use App\Entity\Equeue\EqueueWatch;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EqueueWatch>
 */
class EqueueWatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EqueueWatch::class);
    }

    /**
     * @return list<EqueueWatch>
     */
    public function findAllActive(): array
    {
        /** @var list<EqueueWatch> $result */
        $result = $this->createQueryBuilder('w')
            ->andWhere('w.active = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<EqueueWatch>
     */
    public function findActiveForUser(User $user): array
    {
        /** @var list<EqueueWatch> $result */
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
