<?php

declare(strict_types=1);

namespace App\Monitoring\Infrastructure\Controller;

use App\Monitoring\Infrastructure\MonitoringConfigRepositoryInterface;
use App\User\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/admin/monitoring', name: 'admin_monitoring_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminMonitoringController extends AbstractController
{
    public function __construct(
        private readonly MonitoringConfigRepositoryInterface $monitoringConfigRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $config = $this->monitoringConfigRepository->getConfig();

        return $this->json([
            'enabled' => $config?->isEnabled() ?? true,
            'slotScrapingEnabled' => $config?->isSlotScrapingEnabled() ?? false,
            'updatedAt' => $config?->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedBy' => $config?->getUpdatedBy()?->getEmail(),
        ]);
    }

    #[Route('', name: 'patch', methods: ['PATCH'])]
    public function patch(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON body required.'], 400);
        }

        $hasEnabled = array_key_exists('enabled', $data);
        $hasSlotScraping = array_key_exists('slotScrapingEnabled', $data);

        if ($hasEnabled && !is_bool($data['enabled'])) {
            return $this->json(['error' => 'Field "enabled" must be bool.'], 400);
        }
        if ($hasSlotScraping && !is_bool($data['slotScrapingEnabled'])) {
            return $this->json(['error' => 'Field "slotScrapingEnabled" must be bool.'], 400);
        }
        if (!$hasEnabled && !$hasSlotScraping) {
            return $this->json(['error' => 'Field "enabled" or "slotScrapingEnabled" is required.'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        $config = $this->monitoringConfigRepository->getOrCreate();

        if ($hasEnabled) {
            $config->setEnabled($data['enabled'], $user);
        }
        if ($hasSlotScraping) {
            $config->setSlotScrapingEnabled($data['slotScrapingEnabled'], $user);
        }

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        return $this->json([
            'enabled' => $config->isEnabled(),
            'slotScrapingEnabled' => $config->isSlotScrapingEnabled(),
            'updatedAt' => $config->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'updatedBy' => $config->getUpdatedBy()?->getEmail(),
        ]);
    }
}
