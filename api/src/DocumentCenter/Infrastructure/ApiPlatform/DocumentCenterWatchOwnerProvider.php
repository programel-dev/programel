<?php

declare(strict_types=1);

namespace App\DocumentCenter\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\DocumentCenter\Domain\DocumentCenterWatch;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterWatchRepository;
use App\User\Domain\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<DocumentCenterWatch>
 */
final class DocumentCenterWatchOwnerProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly DocumentCenterWatchRepository $watchRepository,
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
