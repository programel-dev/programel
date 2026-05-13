<?php

declare(strict_types=1);

namespace App\Telegram;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TelegramClient
{
    public function __construct(
        private readonly HttpClientInterface $telegramClient,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TelegramApiException
     */
    public function sendMessage(string $chatId, string $text): array
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TelegramApiException
     */
    public function setWebhook(string $url, string $secretToken): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws TelegramApiException
     */
    private function call(string $method, array $payload): array
    {
        try {
            $response = $this->telegramClient->request('POST', $method, [
                'json' => $payload,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            throw new TelegramApiException(sprintf('Telegram transport failure for %s: %s', $method, $exception->getMessage()), 0, true);
        }

        if ($status >= 200 && $status < 300 && true === ($body['ok'] ?? false)) {
            return $body;
        }

        $description = is_string($body['description'] ?? null) ? $body['description'] : 'Unknown error';
        $retryable = 429 === $status || $status >= 500;

        throw new TelegramApiException(sprintf('Telegram %s failed (%d): %s', $method, $status, $description), $status, $retryable);
    }
}
