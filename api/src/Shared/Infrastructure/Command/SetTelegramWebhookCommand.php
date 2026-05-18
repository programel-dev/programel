<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Command;

use App\Telegram\Infrastructure\TelegramApiException;
use App\Telegram\Infrastructure\TelegramClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:telegram:set-webhook',
    description: 'Register the Telegram bot webhook URL with the public API host',
)]
final class SetTelegramWebhookCommand extends Command
{
    public function __construct(
        private readonly TelegramClient $telegramClient,
        #[Autowire(env: 'TELEGRAM_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'baseUrl',
            InputArgument::REQUIRED,
            'Public base URL of the API (e.g. https://programel.com)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('' === $this->webhookSecret) {
            $io->error('TELEGRAM_WEBHOOK_SECRET is not configured');

            return Command::FAILURE;
        }

        $baseUrl = rtrim((string) $input->getArgument('baseUrl'), '/');
        $url = sprintf('%s/api/v1/telegram/webhook/%s', $baseUrl, $this->webhookSecret);

        try {
            $this->telegramClient->setWebhook($url, $this->webhookSecret);
        } catch (TelegramApiException $exception) {
            $io->error(sprintf('Telegram setWebhook failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Webhook registered: %s', $url));

        return Command::SUCCESS;
    }
}
