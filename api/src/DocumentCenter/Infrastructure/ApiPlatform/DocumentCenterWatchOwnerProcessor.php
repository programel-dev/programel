<?php

declare(strict_types=1);

namespace App\DocumentCenter\Infrastructure\ApiPlatform;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\DocumentCenter\Domain\DocumentCenterWatch;
use App\User\Domain\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @implements ProcessorInterface<DocumentCenterWatch, DocumentCenterWatch>
 */
final class DocumentCenterWatchOwnerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        #[Autowire(service: PersistProcessor::class)]
        private readonly ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        if (null === $data->getUser()) {
            $data->setUser($user);
        } elseif ($data->getUser() !== $user) {
            throw new AccessDeniedHttpException();
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
