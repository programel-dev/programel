<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Equeue\EqueueWatch;
use App\Entity\User;
use App\Repository\Equeue\EqueueWatchRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<EqueueWatch>
 */
final class EqueueWatchOwnerProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EqueueWatchRepository $watchRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $this->watchRepository->createQueryBuilder('w')
            ->andWhere('w.user = :user')
            ->setParameter('user', $user)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
