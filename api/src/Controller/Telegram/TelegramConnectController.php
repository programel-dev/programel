<?php

declare(strict_types=1);

namespace App\Controller\Telegram;

use App\Entity\Telegram\TelegramAccount;
use App\Entity\User;
use App\Repository\Telegram\TelegramAccountRepository;
use App\Telegram\ConnectTokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class TelegramConnectController
{
    public function __construct(
        private readonly Security $security,
        private readonly TelegramAccountRepository $accountRepository,
        private readonly ConnectTokenGenerator $tokenGenerator,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(env: 'TELEGRAM_BOT_USERNAME')]
        private readonly string $botUsername,
    ) {
    }

    #[Route(
        path: '/api/v1/telegram/connect-link',
        name: 'telegram_connect_link',
        methods: ['POST'],
    )]
    public function create(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $account = $this->accountRepository->findByUser($user);
        if (null === $account) {
            $account = new TelegramAccount($user);
            $this->entityManager->persist($account);
        }

        $now = new \DateTimeImmutable();
        $token = $this->tokenGenerator->generate();
        $expiresAt = $this->tokenGenerator->expiresAt($now);
        $account->issueToken($token, $expiresAt);

        $this->entityManager->flush();

        return new JsonResponse([
            'url' => sprintf('https://t.me/%s?start=%s', $this->botUsername, $token),
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route(
        path: '/api/v1/telegram/status',
        name: 'telegram_status',
        methods: ['GET'],
    )]
    public function status(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $account = $this->accountRepository->findByUser($user);

        return new JsonResponse([
            'connected' => null !== $account && $account->isConnected(),
            'connectedAt' => $account?->getConnectedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
