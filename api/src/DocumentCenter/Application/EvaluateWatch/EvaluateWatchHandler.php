<?php

declare(strict_types=1);

namespace App\MessageHandler\Equeue;

use App\Entity\Equeue\EqueueNotification;
use App\Entity\Equeue\EqueueWatch;
use App\Equeue\SlotSignature;
use App\Message\Equeue\EvaluateWatchMessage;
use App\Message\Telegram\SendTelegramMessage;
use App\Repository\Equeue\EqueueNotificationRepository;
use App\Repository\Equeue\EqueueSnapshotRepository;
use App\Repository\Equeue\EqueueWatchRepository;
use App\Repository\Telegram\TelegramAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class EvaluateWatchHandler
{
    public function __construct(
        private readonly EqueueWatchRepository $watchRepository,
        private readonly EqueueSnapshotRepository $snapshotRepository,
        private readonly EqueueNotificationRepository $notificationRepository,
        private readonly TelegramAccountRepository $telegramAccountRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EvaluateWatchMessage $message): void
    {
        $watch = $this->watchRepository->find($message->watchId);
        if (null === $watch || !$watch->isActive()) {
            return;
        }

        $snapshot = $this->snapshotRepository->find($message->snapshotId);
        if (null === $snapshot) {
            return;
        }

        $telegramAccount = $this->telegramAccountRepository->findByUser($watch->getUser());
        if (null === $telegramAccount || !$telegramAccount->isConnected()) {
            $this->logger->info('Skipping evaluation: watch owner has no connected Telegram', [
                'watchId' => $message->watchId,
            ]);

            return;
        }

        $payload = $snapshot->getPayload();
        $slots = is_array($payload['slots'] ?? null) ? $payload['slots'] : [];
        $serviceLabel = $watch->getServiceLabel() ?? $watch->getServiceCode();

        foreach ($slots as $slot) {
            if (!is_array($slot) || !isset($slot['date'], $slot['times']) || !is_array($slot['times'])) {
                continue;
            }

            foreach ($slot['times'] as $time) {
                try {
                    $slotAt = new \DateTimeImmutable($slot['date'].' '.(string) $time);
                } catch (\Exception) {
                    continue;
                }

                if (!$this->dateInRange($slotAt, $watch)) {
                    continue;
                }

                $signature = SlotSignature::for($watch->getServiceCode(), $slotAt);
                if ($this->notificationRepository->exists($watch, $signature)) {
                    continue;
                }

                $notification = new EqueueNotification($watch, $signature);
                $this->entityManager->persist($notification);
                $this->entityManager->flush();

                $this->messageBus->dispatch(new SendTelegramMessage(
                    (string) $telegramAccount->getChatId(),
                    $this->formatMessage($serviceLabel, $slotAt),
                    (int) $notification->getId(),
                ));
            }
        }
    }

    private function dateInRange(\DateTimeImmutable $slotAt, EqueueWatch $watch): bool
    {
        $slotDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $slotAt->format('Y-m-d'));
        if (false === $slotDate) {
            return false;
        }

        $from = $watch->getDateFrom();
        $to = $watch->getDateTo();

        return null !== $from && null !== $to && $slotDate >= $from && $slotDate <= $to;
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
