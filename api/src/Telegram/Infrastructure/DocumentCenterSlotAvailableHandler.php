<?php

declare(strict_types=1);

namespace App\Telegram\Infrastructure;

use App\DocumentCenter\Application\Event\DocumentCenterSlotAvailableEvent;
use App\User\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class DocumentCenterSlotAvailableHandler
{
    public function __construct(
        private readonly TelegramAccountRepository $telegramAccountRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DocumentCenterSlotAvailableEvent $event): void
    {
        $user = $this->entityManager->find(User::class, $event->userId);
        if (null === $user) {
            return;
        }

        $telegramAccount = $this->telegramAccountRepository->findByUser($user);
        if (null === $telegramAccount || !$telegramAccount->isConnected()) {
            $this->logger->info('Skipping notification: user has no connected Telegram', [
                'userId' => $event->userId,
            ]);

            return;
        }

        $this->messageBus->dispatch(new SendTelegramMessage(
            (string) $telegramAccount->getChatId(),
            $this->formatMessage($event->serviceLabel, $event->slotAt),
            $event->notificationId,
        ));
    }

    private function formatMessage(string $serviceLabel, \DateTimeImmutable $slotAt): string
    {
        return sprintf(
            "🟢 Вільний слот!\n\n📋 %s\n📅 %s\n\n%s",
            $serviceLabel,
            $slotAt->format('d.m.Y H:i'),
            'https://munich.pasport.org.ua/solutions/e-queue',
        );
    }
}
