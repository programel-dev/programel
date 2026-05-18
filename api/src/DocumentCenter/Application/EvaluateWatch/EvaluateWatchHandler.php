<?php

declare(strict_types=1);

namespace App\DocumentCenter\Application\EvaluateWatch;

use App\DocumentCenter\Application\Event\DocumentCenterSlotAvailableEvent;
use App\DocumentCenter\Domain\DocumentCenterNotification;
use App\DocumentCenter\Domain\DocumentCenterWatch;
use App\DocumentCenter\Domain\SlotSignature;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterNotificationRepository;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterSnapshotRepository;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterWatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class EvaluateWatchHandler
{
    public function __construct(
        private readonly DocumentCenterWatchRepository $watchRepository,
        private readonly DocumentCenterSnapshotRepository $snapshotRepository,
        private readonly DocumentCenterNotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
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

        $userId = $watch->getUser()?->getId();
        if (null === $userId) {
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

                $notification = new DocumentCenterNotification($watch, $signature);
                $this->entityManager->persist($notification);
                $this->entityManager->flush();

                $this->messageBus->dispatch(new DocumentCenterSlotAvailableEvent(
                    $userId,
                    $slotAt,
                    $watch->getServiceCode(),
                    $serviceLabel,
                    (int) $notification->getId(),
                ));
            }
        }
    }

    private function dateInRange(\DateTimeImmutable $slotAt, DocumentCenterWatch $watch): bool
    {
        $slotDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $slotAt->format('Y-m-d'));
        if (false === $slotDate) {
            return false;
        }

        $from = $watch->getDateFrom();
        $to = $watch->getDateTo();

        return null !== $from && null !== $to && $slotDate >= $from && $slotDate <= $to;
    }
}
