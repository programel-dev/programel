<?php

declare(strict_types=1);

namespace App\Tests\Equeue;

use App\DocumentCenter\Application\Event\DocumentCenterSlotAvailableEvent;
use App\Telegram\Infrastructure\DocumentCenterSlotAvailableHandler;
use App\Telegram\Infrastructure\SendTelegramMessage;
use App\Telegram\Infrastructure\TelegramAccount;
use App\Telegram\Infrastructure\TelegramAccountRepository;
use App\User\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class BroadcastTelegramHandlerTest extends TestCase
{
    public function testDispatchesSendTelegramMessageWhenConnected(): void
    {
        $user = new User();

        $account = $this->createMock(TelegramAccount::class);
        $account->method('isConnected')->willReturn(true);
        $account->method('getChatId')->willReturn('12345');

        $repo = $this->createMock(TelegramAccountRepository::class);
        $repo->method('findByUser')->willReturn($account);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($user);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $msg) use (&$dispatched): Envelope {
                $dispatched[] = $msg;

                return new Envelope($msg);
            }
        );

        $handler = new DocumentCenterSlotAvailableHandler($repo, $em, $bus, new NullLogger());
        $event = new DocumentCenterSlotAvailableEvent(
            1,
            new \DateTimeImmutable('2026-06-01 10:30:00'),
            '4',
            'Паспорт',
            42,
        );
        ($handler)($event);

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(SendTelegramMessage::class, $dispatched[0]);
        self::assertSame('12345', $dispatched[0]->chatId);
        self::assertStringContainsString('01.06.2026', $dispatched[0]->text);
        self::assertSame(42, $dispatched[0]->notificationId);
    }

    public function testDoesNothingWhenNoConnectedAccount(): void
    {
        $user = new User();

        $repo = $this->createMock(TelegramAccountRepository::class);
        $repo->method('findByUser')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($user);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new DocumentCenterSlotAvailableHandler($repo, $em, $bus, new NullLogger());
        $event = new DocumentCenterSlotAvailableEvent(1, new \DateTimeImmutable(), '4', 'Паспорт', 1);
        ($handler)($event);
    }
}
