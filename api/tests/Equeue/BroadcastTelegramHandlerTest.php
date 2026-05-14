<?php

declare(strict_types=1);

namespace App\Tests\Equeue;

use App\Entity\Telegram\TelegramAccount;
use App\Message\Equeue\BroadcastTelegramMessage;
use App\Message\Telegram\SendTelegramMessage;
use App\MessageHandler\Equeue\BroadcastTelegramHandler;
use App\Repository\Telegram\TelegramAccountRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class BroadcastTelegramHandlerTest extends TestCase
{
    public function testDispatchesSendTelegramMessageForEachConnectedAccount(): void
    {
        $account1 = $this->createMock(TelegramAccount::class);
        $account1->method('getChatId')->willReturn('111');

        $account2 = $this->createMock(TelegramAccount::class);
        $account2->method('getChatId')->willReturn('222');

        $repo = $this->createMock(TelegramAccountRepository::class);
        $repo->method('findAllConnected')->willReturn([$account1, $account2]);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $msg) use (&$dispatched): Envelope {
                $dispatched[] = $msg;

                return new Envelope($msg);
            }
        );

        $handler = new BroadcastTelegramHandler($repo, $bus);
        ($handler)(new BroadcastTelegramMessage('⚡️ Вейкап Нео!'));

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(SendTelegramMessage::class, $dispatched[0]);
        self::assertSame('111', $dispatched[0]->chatId);
        self::assertSame('⚡️ Вейкап Нео!', $dispatched[0]->text);
        self::assertInstanceOf(SendTelegramMessage::class, $dispatched[1]);
        self::assertSame('222', $dispatched[1]->chatId);
        self::assertSame('⚡️ Вейкап Нео!', $dispatched[1]->text);
    }

    public function testDoesNothingWhenNoConnectedAccounts(): void
    {
        $repo = $this->createMock(TelegramAccountRepository::class);
        $repo->method('findAllConnected')->willReturn([]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new BroadcastTelegramHandler($repo, $bus);
        ($handler)(new BroadcastTelegramMessage('hello'));
    }
}
