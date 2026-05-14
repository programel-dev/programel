<?php

declare(strict_types=1);

namespace App\Controller\Telegram;

use App\Entity\Telegram\TelegramAccount;
use App\Repository\Telegram\TelegramAccountRepository;
use App\Telegram\TelegramApiException;
use App\Telegram\TelegramClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class TelegramWebhookController extends AbstractController
{
    public function __construct(
        private readonly TelegramAccountRepository $telegramAccountRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramClient $telegramClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'TELEGRAM_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
    ) {
    }

    #[Route(
        path: '/api/v1/telegram/webhook/{secret}',
        name: 'telegram_webhook',
        requirements: ['secret' => '[A-Za-z0-9_\-]+'],
        methods: ['POST'],
    )]
    public function __invoke(string $secret, Request $request): JsonResponse
    {
        if ('' === $this->webhookSecret || !hash_equals($this->webhookSecret, $secret)) {
            throw new NotFoundHttpException();
        }

        $headerSecret = $request->headers->get('X-Telegram-Bot-Api-Secret-Token', '');
        if (!hash_equals($this->webhookSecret, $headerSecret)) {
            throw new NotFoundHttpException();
        }

        $update = json_decode($request->getContent(), true);
        if (!is_array($update)) {
            return new JsonResponse(['ok' => true]);
        }

        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return new JsonResponse(['ok' => true]);
        }

        $text = isset($message['text']) ? trim((string) $message['text']) : '';
        $chat = $message['chat'] ?? null;
        $chatId = is_array($chat) && isset($chat['id']) ? (string) $chat['id'] : null;

        if (null === $chatId || !str_starts_with($text, '/start')) {
            return new JsonResponse(['ok' => true]);
        }

        $parts = preg_split('/\s+/', $text, 2);
        $token = isset($parts[1]) ? trim($parts[1]) : '';
        if ('' === $token) {
            $this->replySafely($chatId, '👋 Привіт! Щоб приєднати акаунт, відкрий посилання з сайту programel.');

            return new JsonResponse(['ok' => true]);
        }

        $account = $this->telegramAccountRepository->findByConnectToken($token);
        $now = new \DateTimeImmutable();
        if (null === $account || !$account->isTokenValid($now)) {
            $this->replySafely($chatId, '⛔ Посилання недійсне або термін дії токена сплинув. Згенеруй нове на сайті.');

            return new JsonResponse(['ok' => true]);
        }

        $this->bind($account, $chatId, $now);
        $this->replySafely($chatId, '✅ Готово! Тепер ти отримуватимеш сповіщення про вільні слоти e-queue.');

        return new JsonResponse(['ok' => true]);
    }

    private function bind(TelegramAccount $account, string $chatId, \DateTimeImmutable $now): void
    {
        $account->bind($chatId, $now);
        $this->entityManager->flush();
    }

    private function replySafely(string $chatId, string $text): void
    {
        try {
            $this->telegramClient->sendMessage($chatId, $text);
        } catch (TelegramApiException $exception) {
            $this->logger->warning('Failed to reply to Telegram chat', [
                'chatId' => $chatId,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
