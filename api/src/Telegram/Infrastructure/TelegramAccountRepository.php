<?php

declare(strict_types=1);

namespace App\Telegram\Infrastructure;

use App\User\Domain\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TelegramAccount>
 */
class TelegramAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramAccount::class);
    }

    public function findByUser(User $user): ?TelegramAccount
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findByConnectToken(string $token): ?TelegramAccount
    {
        return $this->findOneBy(['connectToken' => $token]);
    }

    public function findByChatId(string $chatId): ?TelegramAccount
    {
        return $this->findOneBy(['chatId' => $chatId]);
    }

    /** @return list<TelegramAccount> */
    public function findAllConnected(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.chatId IS NOT NULL')
            ->getQuery()
            ->getResult();
    }
}
