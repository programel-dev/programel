<?php

declare(strict_types=1);

namespace App\Telegram\Infrastructure;

use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class SendTelegramHandler
{
    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly TelegramAccountRepository $telegramAccountRepository,
        private readonly DocumentCenterNotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendTelegramMessage $message): void
    {
        try {
            $result = $this->telegramClient->sendMessage($message->chatId, $message->text);
        } catch (TelegramApiException $exception) {
            if (403 === $exception->httpStatus) {
                $this->unbindBlockedChat($message->chatId);
                throw new UnrecoverableMessageHandlingException(sprintf('Telegram chat %s is blocked; unbound account', $message->chatId), previous: $exception);
            }

            if ($exception->retryable) {
                throw $exception;
            }

            $this->logger->error('Telegram send failed permanently', [
                'chatId' => $message->chatId,
                'status' => $exception->httpStatus,
                'reason' => $exception->getMessage(),
            ]);
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), previous: $exception);
        }

        if (null !== $message->notificationId) {
            $notification = $this->notificationRepository->find($message->notificationId);
            if (null !== $notification) {
                $tgMessageId = $result['result']['message_id'] ?? null;
                $notification->setTelegramMessageId(null !== $tgMessageId ? (string) $tgMessageId : null);
                $this->entityManager->flush();
            }
        }
    }

    private function unbindBlockedChat(string $chatId): void
    {
        $account = $this->telegramAccountRepository->findByChatId($chatId);
        if (null === $account) {
            return;
        }

        $account->setChatId(null);
        $this->entityManager->flush();
    }
}
