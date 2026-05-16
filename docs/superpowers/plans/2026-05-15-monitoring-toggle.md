# Monitoring Toggle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin toggle to enable/disable equeue polling, visible on the main page only to users with `ROLE_ADMIN`.

**Architecture:** A single-row `monitoring_config` DB table holds the `enabled` flag. `PollEqueueHandler` checks it before doing anything. A Symfony controller exposes `GET/PATCH /api/v1/admin/monitoring` (ROLE_ADMIN only). The Next.js main page renders a client toggle for admins with SSR initial state and optimistic UI.

**Tech Stack:** Symfony 7.2, Doctrine ORM, LexikJWT, PHPUnit, Behat, Next.js App Router, TypeScript, Tailwind CSS.

---

## File Map

| Action | Path |
|--------|------|
| Create | `api/src/Entity/MonitoringConfig.php` |
| Create | `api/src/Repository/MonitoringConfigRepository.php` |
| Create | `api/migrations/Version20260515120000.php` |
| Modify | `api/src/MessageHandler/Equeue/PollEqueueHandler.php` |
| Create | `api/tests/MessageHandler/Equeue/PollEqueueHandlerDisabledTest.php` |
| Create | `api/src/Controller/Admin/AdminMonitoringController.php` |
| Modify | `api/tests/Behat/FeatureContext.php` |
| Create | `api/features/admin/monitoring.feature` |
| Create | `frontend/src/lib/monitoring.ts` |
| Create | `frontend/src/app/components/MonitoringToggle.tsx` |
| Modify | `frontend/src/app/page.tsx` |

---

## Task 1: Git branch + MonitoringConfig entity, repository, migration

**Files:**
- Create: `api/src/Entity/MonitoringConfig.php`
- Create: `api/src/Repository/MonitoringConfigRepository.php`
- Create: `api/migrations/Version20260515120000.php`

- [ ] **Step 1.1: Create git branch**

```bash
git checkout main && git pull
git checkout -b feat/admin-monitoring-toggle
```

- [ ] **Step 1.2: Create entity**

Create `api/src/Entity/MonitoringConfig.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitoringConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitoringConfigRepository::class)]
#[ORM\Table(name: 'monitoring_config')]
class MonitoringConfig
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setEnabled(bool $enabled, User $updatedBy): static
    {
        $this->enabled = $enabled;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
```

- [ ] **Step 1.3: Create repository**

Create `api/src/Repository/MonitoringConfigRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoringConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoringConfig>
 */
class MonitoringConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoringConfig::class);
    }

    public function isEnabled(): bool
    {
        return $this->find(1)?->isEnabled() ?? true;
    }

    public function getOrCreate(): MonitoringConfig
    {
        return $this->find(1) ?? new MonitoringConfig();
    }
}
```

- [ ] **Step 1.4: Create migration**

Create `api/migrations/Version20260515120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create monitoring_config table with initial enabled row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE monitoring_config (
            id INT NOT NULL,
            updated_by_id INT DEFAULT NULL,
            enabled BOOLEAN NOT NULL DEFAULT TRUE,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('ALTER TABLE monitoring_config ADD CONSTRAINT fk_monitoring_config_user FOREIGN KEY (updated_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_monitoring_config_updated_by ON monitoring_config (updated_by_id)');
        $this->addSql('COMMENT ON COLUMN monitoring_config.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('INSERT INTO monitoring_config (id, enabled, updated_at) VALUES (1, TRUE, NOW())');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitoring_config DROP CONSTRAINT fk_monitoring_config_user');
        $this->addSql('DROP TABLE monitoring_config');
    }
}
```

- [ ] **Step 1.5: Run migration**

```bash
docker compose -f docker-compose.dev.yml exec api bin/console doctrine:migrations:migrate --no-interaction
```

Expected output: `[notice] Migrating up to DoctrineMigrations\Version20260515120000`

- [ ] **Step 1.6: Commit**

```bash
git add api/src/Entity/MonitoringConfig.php \
        api/src/Repository/MonitoringConfigRepository.php \
        api/migrations/Version20260515120000.php
git commit -m "feat(monitoring): add MonitoringConfig entity, repository, migration"
```

---

## Task 2: PollEqueueHandler guard (TDD)

**Files:**
- Modify: `api/src/MessageHandler/Equeue/PollEqueueHandler.php`
- Create: `api/tests/MessageHandler/Equeue/PollEqueueHandlerDisabledTest.php`

- [ ] **Step 2.1: Write failing test**

Create `api/tests/MessageHandler/Equeue/PollEqueueHandlerDisabledTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Equeue;

use App\Equeue\Fetcher\EqueueFetcherInterface;
use App\Message\Equeue\PollEqueueMessage;
use App\MessageHandler\Equeue\PollEqueueHandler;
use App\Repository\Equeue\EqueueRawHtmlRepository;
use App\Repository\Equeue\EqueueSnapshotRepository;
use App\Repository\MonitoringConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

final class PollEqueueHandlerDisabledTest extends TestCase
{
    public function testHandlerSkipsFetchWhenMonitoringIsDisabled(): void
    {
        $monitoring = $this->createMock(MonitoringConfigRepository::class);
        $monitoring->method('isEnabled')->willReturn(false);

        $fetcher = $this->createMock(EqueueFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->never())->method('createLock');

        $handler = new PollEqueueHandler(
            fetcher: $fetcher,
            rawHtmlRepository: $this->createMock(EqueueRawHtmlRepository::class),
            entityManager: $this->createMock(EntityManagerInterface::class),
            messageBus: $this->createMock(MessageBusInterface::class),
            lockFactory: $lockFactory,
            snapshotRepository: $this->createMock(EqueueSnapshotRepository::class),
            logger: $this->createMock(LoggerInterface::class),
            monitoringConfigRepository: $monitoring,
        );

        $handler(new PollEqueueMessage());
    }
}
```

- [ ] **Step 2.2: Run test — verify it fails**

```bash
docker compose -f docker-compose.dev.yml exec api vendor/bin/simple-phpunit tests/MessageHandler/Equeue/PollEqueueHandlerDisabledTest.php
```

Expected: FAIL — `Unknown named argument $monitoringConfigRepository` (constructor doesn't have it yet).

- [ ] **Step 2.3: Add MonitoringConfigRepository to PollEqueueHandler**

In `api/src/MessageHandler/Equeue/PollEqueueHandler.php`:

Add to imports:
```php
use App\Repository\MonitoringConfigRepository;
```

Add as last parameter in constructor:
```php
    public function __construct(
        private readonly EqueueFetcherInterface $fetcher,
        private readonly EqueueRawHtmlRepository $rawHtmlRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LockFactory $lockFactory,
        private readonly EqueueSnapshotRepository $snapshotRepository,
        private readonly LoggerInterface $logger,
        private readonly MonitoringConfigRepository $monitoringConfigRepository,
    ) {
    }
```

Add guard as the **first two lines** of `__invoke()`, before the lock:
```php
    public function __invoke(PollEqueueMessage $message): void
    {
        if (!$this->monitoringConfigRepository->isEnabled()) {
            $this->logger->info('equeue polling disabled, skipping');

            return;
        }

        $lock = $this->lockFactory->createLock('equeue.poll', ttl: 120.0, autoRelease: true);
        // ... rest unchanged
```

- [ ] **Step 2.4: Run test — verify it passes**

```bash
docker compose -f docker-compose.dev.yml exec api vendor/bin/simple-phpunit tests/MessageHandler/Equeue/PollEqueueHandlerDisabledTest.php
```

Expected: `OK (1 test, 1 assertion)`

- [ ] **Step 2.5: Run full PHPUnit suite — no regressions**

```bash
docker compose -f docker-compose.dev.yml exec api vendor/bin/simple-phpunit
```

Expected: all tests pass.

- [ ] **Step 2.6: Commit**

```bash
git add api/src/MessageHandler/Equeue/PollEqueueHandler.php \
        api/tests/MessageHandler/Equeue/PollEqueueHandlerDisabledTest.php
git commit -m "feat(monitoring): guard PollEqueueHandler behind monitoring enabled flag"
```

---

## Task 3: AdminMonitoringController + Behat tests (TDD)

**Files:**
- Modify: `api/tests/Behat/FeatureContext.php`
- Create: `api/features/admin/monitoring.feature`
- Create: `api/src/Controller/Admin/AdminMonitoringController.php`

- [ ] **Step 3.1: Extend FeatureContext with auth and body support**

Replace the full content of `api/tests/Behat/FeatureContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Entity\User;
use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

final class FeatureContext implements Context
{
    private ?Response $response = null;
    private ?string $authToken = null;

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * @Given I am authenticated as admin
     */
    public function iAmAuthenticatedAsAdmin(): void
    {
        $this->authToken = $this->createTokenForUser('behat_admin@test.com', ['ROLE_USER', 'ROLE_ADMIN']);
    }

    /**
     * @Given I am authenticated as a regular user
     */
    public function iAmAuthenticatedAsRegularUser(): void
    {
        $this->authToken = $this->createTokenForUser('behat_user@test.com', ['ROLE_USER']);
    }

    /**
     * @Given I am not authenticated
     */
    public function iAmNotAuthenticated(): void
    {
        $this->authToken = null;
    }

    /**
     * @When I send a :method request to :url
     */
    public function iSendARequestTo(string $method, string $url): void
    {
        $request = Request::create($url, $method);
        if (null !== $this->authToken) {
            $request->headers->set('Authorization', 'Bearer '.$this->authToken);
        }
        $this->response = $this->kernel->handle($request);
    }

    /**
     * @When I send a :method request to :url with body:
     */
    public function iSendARequestToWithBody(string $method, string $url, PyStringNode $body): void
    {
        $request = Request::create(
            $url,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            (string) $body,
        );
        if (null !== $this->authToken) {
            $request->headers->set('Authorization', 'Bearer '.$this->authToken);
        }
        $this->response = $this->kernel->handle($request);
    }

    /**
     * @Then the response status code should be :statusCode
     */
    public function theResponseStatusCodeShouldBe(int $statusCode): void
    {
        if ($this->response->getStatusCode() !== $statusCode) {
            throw new \RuntimeException(sprintf(
                'Expected status code %d, got %d. Body: %s',
                $statusCode,
                $this->response->getStatusCode(),
                $this->response->getContent(),
            ));
        }
    }

    /**
     * @Then the response should be in JSON
     */
    public function theResponseShouldBeInJson(): void
    {
        $content = $this->response->getContent();
        if (null === json_decode($content)) {
            throw new \RuntimeException('Response is not valid JSON: '.$content);
        }
    }

    /**
     * @Then the JSON node :node should be equal to :expected
     */
    public function theJsonNodeShouldBeEqualTo(string $node, string $expected): void
    {
        $data = json_decode($this->response->getContent(), true);
        $value = $this->getJsonNode($data, $node);
        $valueStr = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        if ($valueStr !== $expected) {
            throw new \RuntimeException(sprintf(
                'Expected JSON node "%s" to equal "%s", got "%s".',
                $node,
                $expected,
                $valueStr,
            ));
        }
    }

    /**
     * @Then the JSON node :node should exist
     */
    public function theJsonNodeShouldExist(string $node): void
    {
        $data = json_decode($this->response->getContent(), true);
        $this->getJsonNode($data, $node);
    }

    private function getJsonNode(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!array_key_exists($key, $current)) {
                throw new \RuntimeException(sprintf(
                    'JSON node "%s" not found in: %s',
                    $path,
                    json_encode($data),
                ));
            }
            $current = $current[$key];
        }

        return $current;
    }

    private function createTokenForUser(string $email, array $roles): string
    {
        $container = $this->kernel->getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $jwtManager = $container->get('lexik_jwt_authentication.jwt_manager');
        $hasher = $container->get('security.user_password_hasher');

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $em->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);

        if (null === $user) {
            $user = new User();
            $user->setEmail($email);
            $user->setPassword($hasher->hashPassword($user, 'behat-password'));
            $user->setRoles($roles);
            $em->persist($user);
            $em->flush();
        }

        return $jwtManager->create($user);
    }
}
```

- [ ] **Step 3.2: Write Behat feature file**

Create `api/features/admin/monitoring.feature`:

```gherkin
Feature: Admin monitoring toggle

  Scenario: Unauthenticated request is rejected
    Given I am not authenticated
    When I send a "GET" request to "/api/v1/admin/monitoring"
    Then the response status code should be 401

  Scenario: Regular user cannot read monitoring status
    Given I am authenticated as a regular user
    When I send a "GET" request to "/api/v1/admin/monitoring"
    Then the response status code should be 403

  Scenario: Admin can read monitoring status
    Given I am authenticated as admin
    When I send a "GET" request to "/api/v1/admin/monitoring"
    Then the response status code should be 200
    And the response should be in JSON
    And the JSON node "enabled" should exist

  Scenario: Admin can disable monitoring
    Given I am authenticated as admin
    When I send a "PATCH" request to "/api/v1/admin/monitoring" with body:
      """
      {"enabled": false}
      """
    Then the response status code should be 200
    And the JSON node "enabled" should be equal to "false"

  Scenario: Admin can re-enable monitoring
    Given I am authenticated as admin
    When I send a "PATCH" request to "/api/v1/admin/monitoring" with body:
      """
      {"enabled": true}
      """
    Then the response status code should be 200
    And the JSON node "enabled" should be equal to "true"

  Scenario: Regular user cannot toggle monitoring
    Given I am authenticated as a regular user
    When I send a "PATCH" request to "/api/v1/admin/monitoring" with body:
      """
      {"enabled": false}
      """
    Then the response status code should be 403

  Scenario: PATCH with missing field returns 400
    Given I am authenticated as admin
    When I send a "PATCH" request to "/api/v1/admin/monitoring" with body:
      """
      {}
      """
    Then the response status code should be 400
```

- [ ] **Step 3.3: Run Behat — verify scenarios fail**

```bash
docker compose -f docker-compose.dev.yml exec api vendor/bin/behat features/admin/monitoring.feature
```

Expected: all scenarios fail with `404` (controller not found yet).

- [ ] **Step 3.4: Create controller**

Create `api/src/Controller/Admin/AdminMonitoringController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\MonitoringConfigRepository;
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
        private readonly MonitoringConfigRepository $monitoringConfigRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $config = $this->monitoringConfigRepository->find(1);

        return $this->json([
            'enabled' => $config?->isEnabled() ?? true,
            'updatedAt' => $config?->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'updatedBy' => $config?->getUpdatedBy()?->getEmail(),
        ]);
    }

    #[Route('', name: 'patch', methods: ['PATCH'])]
    public function patch(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !array_key_exists('enabled', $data) || !is_bool($data['enabled'])) {
            return $this->json(['error' => 'Field "enabled" (bool) is required.'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        $config = $this->monitoringConfigRepository->getOrCreate();
        $config->setEnabled($data['enabled'], $user);

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        return $this->json([
            'enabled' => $config->isEnabled(),
            'updatedAt' => $config->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'updatedBy' => $config->getUpdatedBy()?->getEmail(),
        ]);
    }
}
```

- [ ] **Step 3.5: Run Behat — verify scenarios pass**

```bash
docker compose -f docker-compose.dev.yml exec api vendor/bin/behat features/admin/monitoring.feature
```

Expected: `7 scenarios (7 passed)`.

- [ ] **Step 3.6: Run full Behat suite — no regressions**

```bash
docker compose -f docker-compose.dev.yml exec api vendor/bin/behat
```

Expected: all scenarios pass.

- [ ] **Step 3.7: Commit**

```bash
git add api/tests/Behat/FeatureContext.php \
        api/features/admin/monitoring.feature \
        api/src/Controller/Admin/AdminMonitoringController.php
git commit -m "feat(monitoring): add AdminMonitoringController with GET/PATCH, extend Behat auth context"
```

---

## Task 4: Frontend — monitoring lib + MonitoringToggle component

**Files:**
- Create: `frontend/src/lib/monitoring.ts`
- Create: `frontend/src/app/components/MonitoringToggle.tsx`

- [ ] **Step 4.1: Create monitoring lib**

Create `frontend/src/lib/monitoring.ts`:

```typescript
import { apiFetch } from "./api";

export interface MonitoringStatus {
  enabled: boolean;
  updatedAt: string | null;
  updatedBy: string | null;
}

export async function getMonitoringStatus(token: string): Promise<MonitoringStatus> {
  return apiFetch<MonitoringStatus>("/admin/monitoring", {
    headers: { Authorization: `Bearer ${token}` },
  });
}

export async function setMonitoringEnabled(enabled: boolean): Promise<MonitoringStatus> {
  return apiFetch<MonitoringStatus>("/admin/monitoring", {
    method: "PATCH",
    body: JSON.stringify({ enabled }),
  });
}
```

Note: `getMonitoringStatus` is called server-side (SSR) and requires an explicit `Authorization` header since `apiFetch` omits credentials on the server. `setMonitoringEnabled` is called client-side where `credentials: "include"` sends the BEARER cookie automatically.

- [ ] **Step 4.2: Create MonitoringToggle component**

Create `frontend/src/app/components/MonitoringToggle.tsx`:

```tsx
"use client";

import { useState } from "react";
import { MonitoringStatus, setMonitoringEnabled } from "@/lib/monitoring";

interface Props {
  initial: MonitoringStatus;
}

export function MonitoringToggle({ initial }: Props) {
  const [status, setStatus] = useState<MonitoringStatus>(initial);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleToggle() {
    const next = !status.enabled;
    setSaving(true);
    setError(null);
    setStatus((prev) => ({ ...prev, enabled: next }));

    try {
      const updated = await setMonitoringEnabled(next);
      setStatus(updated);
    } catch {
      setStatus(status);
      setError("Не вдалося зберегти");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="rounded-xl border border-zinc-200 p-5 dark:border-zinc-800">
      <p className="mb-3 text-xs font-semibold uppercase tracking-widest text-zinc-400">
        Адмін
      </p>
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
          Polling equeue
        </span>
        <button
          role="switch"
          aria-checked={status.enabled}
          onClick={handleToggle}
          disabled={saving}
          className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors disabled:opacity-50 ${
            status.enabled
              ? "bg-sky-600"
              : "bg-zinc-300 dark:bg-zinc-600"
          }`}
        >
          <span
            className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
              status.enabled ? "translate-x-6" : "translate-x-1"
            }`}
          />
        </button>
      </div>
      {status.updatedBy && (
        <p className="mt-2 text-xs text-zinc-400 dark:text-zinc-500">
          {formatRelative(status.updatedAt)} · {status.updatedBy}
        </p>
      )}
      {error && (
        <p className="mt-2 text-xs text-red-500">{error}</p>
      )}
    </div>
  );
}

function formatRelative(dateStr: string | null): string {
  if (!dateStr) return "";
  const diffMin = Math.floor((Date.now() - new Date(dateStr).getTime()) / 60_000);
  if (diffMin < 1) return "щойно";
  if (diffMin < 60) return `${diffMin} хв тому`;
  return `${Math.floor(diffMin / 60)} год тому`;
}
```

- [ ] **Step 4.3: Commit**

```bash
git add frontend/src/lib/monitoring.ts \
        frontend/src/app/components/MonitoringToggle.tsx
git commit -m "feat(monitoring): add monitoring lib and MonitoringToggle component"
```

---

## Task 5: Frontend — main page integration

**Files:**
- Modify: `frontend/src/app/page.tsx`

- [ ] **Step 5.1: Modify page.tsx**

Replace the full content of `frontend/src/app/page.tsx`:

```tsx
import Link from "next/link";
import { cookies } from "next/headers";
import { MonitoringToggle } from "@/app/components/MonitoringToggle";
import { getMonitoringStatus } from "@/lib/monitoring";

const tools = [
  {
    href: "/equeue",
    title: "e-queue моніторинг",
    description:
      "Відстежуй вільні слоти консульства України в Мюнхені. Отримуй сповіщення в Telegram щойно з'явиться місце.",
  },
];

function decodeJwtRoles(token: string): string[] {
  try {
    const payload = JSON.parse(
      Buffer.from(token.split(".")[1], "base64url").toString(),
    );
    return Array.isArray(payload.roles) ? payload.roles : [];
  } catch {
    return [];
  }
}

export default async function HomePage() {
  const cookieStore = await cookies();
  const token = cookieStore.get("BEARER")?.value;
  const isAdmin = token
    ? decodeJwtRoles(token).includes("ROLE_ADMIN")
    : false;

  const monitoringStatus = isAdmin && token
    ? await getMonitoringStatus(token).catch(() => null)
    : null;

  return (
    <main className="mx-auto w-full max-w-3xl space-y-10 p-4 sm:p-8">
      <section>
        <h2 className="mb-4 text-xs font-semibold uppercase tracking-widest text-zinc-400">
          Інструменти
        </h2>
        <ul className="space-y-3">
          {tools.map((tool) => (
            <li key={tool.href}>
              <Link
                href={tool.href}
                className="flex flex-col gap-1 rounded-xl border border-zinc-200 p-5 transition-colors hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:border-zinc-600 dark:hover:bg-zinc-900"
              >
                <span className="font-semibold text-zinc-900 dark:text-zinc-50">
                  {tool.title}
                </span>
                <span className="text-sm text-zinc-500 dark:text-zinc-400">
                  {tool.description}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </section>

      {monitoringStatus && (
        <section>
          <MonitoringToggle initial={monitoringStatus} />
        </section>
      )}
    </main>
  );
}
```

- [ ] **Step 5.2: Start dev environment and verify**

```bash
make dev
```

Open `http://localhost:3000` (or `https://programel.local`):
1. Log in as a **regular user** → toggle is NOT visible on the main page
2. Log in as an **admin user** → toggle IS visible, shows current polling state
3. Click the toggle → it flips immediately (optimistic), API call succeeds, status text updates
4. Click toggle again → reverts back, API call succeeds
5. Open a second browser tab as admin → both tabs reflect the same state after refresh

- [ ] **Step 5.3: Run linters**

```bash
make lint
```

Expected: no errors from eslint or tsc.

- [ ] **Step 5.4: Commit**

```bash
git add frontend/src/app/page.tsx
git commit -m "feat(monitoring): show monitoring toggle on main page for admins"
```

---

## Verification Checklist

After all tasks complete:

- [ ] `make test` passes (PHPUnit + Behat + frontend tests)
- [ ] `make lint` passes (PHP-CS-Fixer, PHPStan, ESLint, tsc)
- [ ] Regular user on main page: no toggle visible
- [ ] Admin user on main page: toggle visible, correct initial state
- [ ] Toggle off → worker logs `equeue polling disabled, skipping` on next poll tick
- [ ] Toggle back on → worker resumes polling normally
- [ ] Non-admin JWT hitting `GET /api/v1/admin/monitoring` returns 403
- [ ] Unauthenticated `GET /api/v1/admin/monitoring` returns 401
