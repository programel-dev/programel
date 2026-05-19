<?php

declare(strict_types=1);

namespace App\Tests\DocumentCenter;

use App\DocumentCenter\Application\BroadcastSlotsAvailable\BroadcastSlotsAvailableHandler;
use App\DocumentCenter\Application\BroadcastSlotsAvailable\BroadcastSlotsAvailableMessage;
use App\DocumentCenter\Domain\DocumentCenterWatch;
use App\DocumentCenter\Infrastructure\Doctrine\DocumentCenterWatchRepository;
use App\Telegram\Infrastructure\SendTelegramMessage;
use App\Telegram\Infrastructure\TelegramAccount;
use App\Telegram\Infrastructure\TelegramAccountRepository;
use App\User\Domain\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class BroadcastSlotsAvailableHandlerTest extends TestCase
{
    public function testDispatchesToAllConnectedWatches(): void
    {
        $user1 = $this->createMock(User::class);
        $user2 = $this->createMock(User::class);

        $watch1 = $this->createWatch($user1);
        $watch2 = $this->createWatch($user2);

        $account1 = $this->createConnectedAccount('111');
        $account2 = $this->createConnectedAccount('222');

        $watchRepo = $this->createMock(DocumentCenterWatchRepository::class);
        $watchRepo->method('findAllActive')->willReturn([$watch1, $watch2]);

        $telegramRepo = $this->createMock(TelegramAccountRepository::class);
        $telegramRepo->method('findByUser')->willReturnMap([
            [$user1, $account1],
            [$user2, $account2],
        ]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched): Envelope {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $handler = new BroadcastSlotsAvailableHandler($watchRepo, $telegramRepo, $bus, new NullLogger());
        ($handler)(new BroadcastSlotsAvailableMessage());

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(SendTelegramMessage::class, $dispatched[0]);
        self::assertSame('111', $dispatched[0]->chatId);
        self::assertStringContainsString('Зʼявились вільні слоти', $dispatched[0]->text);
        self::assertInstanceOf(SendTelegramMessage::class, $dispatched[1]);
        self::assertSame('222', $dispatched[1]->chatId);
    }

    public function testFormattedMessageWithSlots(): void
    {
        $user = $this->createMock(User::class);
        $watch = $this->createWatch($user);
        $account = $this->createConnectedAccount('123');

        $watchRepo = $this->createMock(DocumentCenterWatchRepository::class);
        $watchRepo->method('findAllActive')->willReturn([$watch]);

        $telegramRepo = $this->createMock(TelegramAccountRepository::class);
        $telegramRepo->method('findByUser')->willReturn($account);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched): Envelope {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $handler = new BroadcastSlotsAvailableHandler($watchRepo, $telegramRepo, $bus, new NullLogger());
        ($handler)(new BroadcastSlotsAvailableMessage('20 травня 2026', ['10:30 — 5 вільних слотів', '11:00 — 3 вільних слоти']));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(SendTelegramMessage::class, $dispatched[0]);
        $text = $dispatched[0]->text;
        self::assertStringContainsString('📅 20 травня 2026', $text);
        self::assertStringContainsString('• 10:30 — 5 вільних слотів', $text);
        self::assertStringContainsString('• 11:00 — 3 вільних слоти', $text);
        self::assertStringNotContainsString('Зʼявились', $text);
    }

    public function testFallbackMessageWhenNoSlots(): void
    {
        $user = $this->createMock(User::class);
        $watch = $this->createWatch($user);
        $account = $this->createConnectedAccount('123');

        $watchRepo = $this->createMock(DocumentCenterWatchRepository::class);
        $watchRepo->method('findAllActive')->willReturn([$watch]);

        $telegramRepo = $this->createMock(TelegramAccountRepository::class);
        $telegramRepo->method('findByUser')->willReturn($account);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched): Envelope {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $handler = new BroadcastSlotsAvailableHandler($watchRepo, $telegramRepo, $bus, new NullLogger());
        ($handler)(new BroadcastSlotsAvailableMessage(null, []));

        self::assertCount(1, $dispatched);
        self::assertStringContainsString('Зʼявились вільні слоти', $dispatched[0]->text);
        self::assertStringNotContainsString('📅', $dispatched[0]->text);
    }

    public function testSkipsWatchWithNoConnectedTelegram(): void
    {
        $user = $this->createMock(User::class);
        $watch = $this->createWatch($user);

        $watchRepo = $this->createMock(DocumentCenterWatchRepository::class);
        $watchRepo->method('findAllActive')->willReturn([$watch]);

        $telegramRepo = $this->createMock(TelegramAccountRepository::class);
        $telegramRepo->method('findByUser')->willReturn(null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new BroadcastSlotsAvailableHandler($watchRepo, $telegramRepo, $bus, new NullLogger());
        ($handler)(new BroadcastSlotsAvailableMessage());
    }

    public function testSkipsDisconnectedTelegramAccount(): void
    {
        $user = $this->createMock(User::class);
        $watch = $this->createWatch($user);

        $account = $this->createMock(TelegramAccount::class);
        $account->method('isConnected')->willReturn(false);

        $watchRepo = $this->createMock(DocumentCenterWatchRepository::class);
        $watchRepo->method('findAllActive')->willReturn([$watch]);

        $telegramRepo = $this->createMock(TelegramAccountRepository::class);
        $telegramRepo->method('findByUser')->willReturn($account);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new BroadcastSlotsAvailableHandler($watchRepo, $telegramRepo, $bus, new NullLogger());
        ($handler)(new BroadcastSlotsAvailableMessage());
    }

    public function testNoActiveWatchesDispatchesNothing(): void
    {
        $watchRepo = $this->createMock(DocumentCenterWatchRepository::class);
        $watchRepo->method('findAllActive')->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new BroadcastSlotsAvailableHandler(
            $watchRepo,
            $this->createMock(TelegramAccountRepository::class),
            $bus,
            new NullLogger(),
        );
        ($handler)(new BroadcastSlotsAvailableMessage());
    }

    private function createWatch(User $user): DocumentCenterWatch
    {
        $watch = $this->createMock(DocumentCenterWatch::class);
        $watch->method('getUser')->willReturn($user);

        return $watch;
    }

    private function createConnectedAccount(string $chatId): TelegramAccount
    {
        $account = $this->createMock(TelegramAccount::class);
        $account->method('isConnected')->willReturn(true);
        $account->method('getChatId')->willReturn($chatId);

        return $account;
    }
}
