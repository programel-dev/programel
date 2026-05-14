<?php

declare(strict_types=1);

namespace App\MessageHandler\Equeue;

use App\Message\Equeue\BroadcastTelegramMessage;
use App\Message\Telegram\SendTelegramMessage;
use App\Repository\Telegram\TelegramAccountRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class BroadcastTelegramHandler
{
    public function __construct(
        private readonly TelegramAccountRepository $telegramAccountRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(BroadcastTelegramMessage $message): void
    {
        foreach ($this->telegramAccountRepository->findAllConnected() as $account) {
            $this->messageBus->dispatch(new SendTelegramMessage(
                (string) $account->getChatId(),
                $message->text,
            ));
        }
    }
}
