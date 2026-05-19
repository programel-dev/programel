# Architecture: два середовища, всі сервіси

## Середовища

```
┌─────────────────────────────────────────────────────────────────────┐
│  DEV MACHINE (MacBook)                                              │
│  Репозиторій: ~/Projects/programel                                  │
│  docker-compose.dev.yml                                             │
│  Сервіси: api (php-fpm), frontend (next.js dev), nginx, postgres,   │
│           redis, flaresolverr                                       │
└─────────────────────────────────────────────────────────────────────┘
               │  git push / PR merge
               ▼
┌─────────────────────────────────────────────────────────────────────┐
│  GITHUB ACTIONS (ubuntu-latest runner)                              │
│  Тригери: push main/develop, PR to main                             │
│                                                                     │
│  Jobs (sequential):                                                 │
│  1. lint  → PHP-CS-Fixer, PHPStan, ESLint, tsc                      │
│  2. test  → PHPUnit, Behat (потребує postgres+redis сервісів)       │
│  3. build → (тільки main) build+push 3 images до GHCR               │
│  4. deploy→ (тільки main) SCP + SSH на Droplet                      │
└─────────────────────────────────────────────────────────────────────┘
               │                              │
               │ push images                  │ SCP + SSH deploy
               ▼                              ▼
┌──────────────────────────┐   ┌──────────────────────────────────────┐
│  GHCR                    │   │  SERVER  (HETZNER)                   │
│  ghcr.io/programel-dev/  │   │  /var/www/programel                  │
│  programel/              │   │  docker-compose.prod.yml             │
│    api:latest            │   │                                      │
│    api:{sha}             │   │  Сервіси:                            │
│    frontend:latest       │   │  - api (php-fpm)                     │
│    frontend:{sha}        │   │  - frontend (next.js)                │
│    playwright-equeue:    │   │  - nginx (SSL termination)           │
│      latest              │   │  - postgres                          │
│      {sha}               │   │  - redis                             │
└──────────────┬───────────┘   │  - flaresolverr                      │
               │               └──────────────────────────────────────┘
               │ docker pull
               ▼
┌──────────────────────────────────────────────────────────────────────┐
│  MAC MINI (Ubuntu Server)  192.168.2.140                             │
│  /home/serg/programel                                                │
│  docker-compose.home.yml                                             │
│                                                                      │
│  Сервіси:                                                            │
│  - playwright-equeue  (порт 3001, stealth Chromium scraper)          │
│  - flaresolverr       (порт 8191, Cloudflare bypass fallback)        │
│  - worker             (php messenger:consume async scheduler_default)│
│  - watchtower         (автооновлення images кожні 5 хв)              │
│                                                                      │
│  Автоматизація:                                                      │
│  - Watchtower → стежить за :latest тегами api + playwright-equeue    │
│  - systemd timer → git pull + compose up -d кожні 5 хв               │
└──────────────────────────────────────────────────────────────────────┘
```

## Детальна схема: що запускає що

### Droplet (повністю через CI)

```
PR merge → CI deploy job (lines 204-235 у ci.yml):
  1. scp docker-compose.prod.yml + docker/nginx/ → /var/www/programel
  2. ssh: cd /var/www/programel
          export IMAGE_TAG={sha}
          docker compose pull
          docker compose up -d
          docker exec api bin/console doctrine:migrations:migrate --no-interaction
          nginx -s reload
```

Після merge нових `docker-compose.prod.yml` або `docker/nginx/` змін — Droplet оновлюється автоматично протягом часу CI (зазвичай 3-7 хв).

### Mac mini (Watchtower + systemd)

```
CI push playwright-equeue:latest до GHCR
    │
    │  (≤5 хвилин, Watchtower poll interval)
    ▼
Watchtower на Mac mini:
    docker pull ghcr.io/programel-dev/programel/playwright-equeue:latest
    docker stop programel-playwright-equeue-1
    docker run ... (новий image)

CI push api:latest до GHCR
    │
    │  (≤5 хвилин)
    ▼
Watchtower:
    docker pull ghcr.io/programel-dev/programel/api:latest
    docker stop programel-worker-1
    docker run ... (новий image)

PR merge → composer commit у main
    │
    │  (≤5 хвилин, systemd timer interval)
    ▼
systemd timer → /home/serg/programel/scripts/deploy-home.sh:
    git fetch origin main
    [ HEAD == origin/main ] → exit (нічого не робити)
    git pull --ff-only origin main
    docker compose -f docker-compose.home.yml pull
    docker compose -f docker-compose.home.yml up -d
    # Docker Compose підніме лише змінені сервіси
    # (нові env vars → recreate affected, нові сервіси → start new)
```

## GHCR: яке середовище читає які images

| Image | Droplet | Mac mini |
|-------|---------|----------|
| `api:latest` | ✓ CI pull в deploy job | ✓ Watchtower |
| `frontend:latest` | ✓ CI pull в deploy job | — (не запускається) |
| `playwright-equeue:latest` | — (не запускається) | ✓ Watchtower |

## Compose-файли

| Файл | Де живе | Як потрапляє на сервер |
|------|---------|------------------------|
| `docker-compose.dev.yml` | git репо | Тільки локально (dev machine) |
| `docker-compose.prod.yml` | git репо | CI scp → `/var/www/programel/` |
| `docker-compose.home.yml` | git репо | systemd timer → `git pull` → `/home/serg/programel/` |
| `docker-compose.override.yml` | НЕ у git (gitignore) | Тільки локально (dev debugging) |

## Схема портів і мережі (Mac mini)

```
docker network: programel_default (172.18.0.0/16)

playwright-equeue → :3001 (тільки у compose network, не expose зовні)
flaresolverr      → :8191 (тільки у compose network)
worker            → (no ports, тільки споживає Messenger + стукає до playwright/flaresolverr)
watchtower        → (no ports, читає /var/run/docker.sock)

Worker → host.docker.internal:5432  (autossh тунель → Droplet postgres)
Worker → host.docker.internal:6379  (autossh тунель → Droplet redis)
```

## CI/CD environment secrets (GitHub → Settings → Environments → production)

| Secret            | Де використовується                 |
|-------------------|-------------------------------------|
| `SERVER_HOST`     | SSH/SCP до Droplet у deploy job     |
| `SERVER_USER`     | SSH user на Droplet                 |
| `SSH_PRIVATE_KEY` | SSH private key для Droplet         |
| `GITHUB_TOKEN`    | Автоматичний — docker login до GHCR |

Mac mini не має GitHub secrets — він сам тягне з GHCR через `docker login` (збережений у `~/.docker/config.json`).
