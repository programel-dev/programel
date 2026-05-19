# Programel — Deploy Workflow Guide

> Актуально після PR #30 (chore(deploy): automate Mac mini deploy via GHCR + launchd).

## Файли гайду

| Файл | Зміст |
|------|-------|
| [01-architecture.md](01-architecture.md) | Повна архітектура: два середовища, всі сервіси, схема автодеплою |
| [02-ubuntu-bootstrap.md](02-ubuntu-bootstrap.md) | One-time setup Ubuntu Server (Mac mini) — від чистої машини до повністю автоматизованого деплою |
| [03-day-to-day.md](03-day-to-day.md) | Щоденний workflow: від зміни коду до live production |
| [04-monitoring.md](04-monitoring.md) | Логи, діагностика, troubleshooting |

## TL;DR

```
Зміна у будь-якому файлі → commit → PR → merge в main
                                              │
                              ┌───────────────┴───────────────┐
                              ▼                               ▼
                    GitHub Actions CI                    (нічого більше
                    │                                    робити не треба)
                    ├─ lint + test
                    ├─ build + push до GHCR:
                    │    api:latest
                    │    frontend:latest
                    │    playwright-equeue:latest
                    └─ deploy на Droplet (SSH)
                         docker compose pull && up -d
                         doctrine:migrations:migrate
                         nginx -s reload

               Mac mini (Ubuntu) — паралельно:
               ├─ Watchtower (кожні 5 хв): бачить нові :latest тег →
               │    pull api:latest → restart worker
               │    pull playwright-equeue:latest → restart playwright-equeue
               └─ systemd timer (кожні 5 хв): git fetch origin/main →
                    якщо є нові commits → git pull + docker compose up -d
                    (підхоплює зміни docker-compose.home.yml: нові env, сервіси)
```

## Застарілі файли (до PR #30)

`deployment-guide.md`, `mac-mini-worker-setup.md`, `pi-worker-setup.md`, `changes-workflow.md`, `prod-db-connection-spec.md` — написані до автоматизації. Актуальний гайд — файли 01-04 вище.

---

## Єдиний ручний крок, що залишився

`.env` — секрети не зберігаються у git. Нове secret-значення (API key, пароль) треба додавати вручну на кожному середовищі.
