<?php

declare(strict_types=1);

namespace App\DocumentCenter\Application\BroadcastSlotsAvailable;

use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterWatchRepository;
use App\Telegram\Infrastructure\SendTelegramMessage;
use App\Telegram\Infrastructure\TelegramAccountRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class BroadcastSlotsAvailableHandler
{
    private const QUEUE_URL = 'https://munich.pasport.org.ua/solutions/e-queue';
    private const GENERIC_TEXT = "🟢 Зʼявились вільні слоти на e-queue!\n\n".self::QUEUE_URL;

    public function __construct(
        private readonly DocumentCenterWatchRepository $watchRepository,
        private readonly TelegramAccountRepository $telegramAccountRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BroadcastSlotsAvailableMessage $message): void
    {
        $text = $this->formatMessage($message);
        $watches = $this->watchRepository->findAllActive();
        $sent = 0;

        foreach ($watches as $watch) {
            $account = $this->telegramAccountRepository->findByUser($watch->getUser());
            if (null === $account || !$account->isConnected()) {
                continue;
            }

            $this->messageBus->dispatch(new SendTelegramMessage(
                (string) $account->getChatId(),
                $text,
            ));
            ++$sent;
        }

        $this->logger->info('e-queue slots available broadcast sent', [
            'watches' => count($watches),
            'sent' => $sent,
        ]);
    }

    private function formatMessage(BroadcastSlotsAvailableMessage $message): string
    {
        if (null === $message->date || [] === $message->slots) {
            return self::GENERIC_TEXT;
        }

        $lines = array_map(fn (string $slot) => '• '.$slot, $message->slots);

        return "🟢 Вільні слоти на e-queue!\n\n📅 {$message->date}\n".implode("\n", $lines)."\n\n".self::QUEUE_URL;
    }
}
