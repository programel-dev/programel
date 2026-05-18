<?php

declare(strict_types=1);

namespace App\User\Infrastructure;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

#[AsDoctrineListener(event: Events::prePersist)]
final class RefreshTokenCleanupListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof RefreshToken) {
            return;
        }

        $username = $entity->getUsername();
        if (!$username) {
            return;
        }

        $this->entityManager->createQueryBuilder()
            ->delete(RefreshToken::class, 'rt')
            ->where('rt.username = :username')
            ->setParameter('username', $username)
            ->getQuery()
            ->execute();
    }
}
