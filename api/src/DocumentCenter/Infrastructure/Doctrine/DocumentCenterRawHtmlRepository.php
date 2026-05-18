<?php

declare(strict_types=1);

namespace App\DocumentCenter\Infrastructure\Doctrine;

use App\DocumentCenter\Domain\DocumentCenterRawHtml;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentCenterRawHtml>
 */
class DocumentCenterRawHtmlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentCenterRawHtml::class);
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
