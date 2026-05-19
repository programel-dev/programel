# Day-to-Day Workflow

## Загальне правило

**Будь-яка зміна → commit → PR → merge → все деплоїться само.**

Нижче — конкретні сценарії з часовими рамками коли зміна потрапить у production/Mac mini.

---

## Сценарій 1: Зміна PHP коду (api/)

**Що змінюєш:** `api/src/**`, `api/config/**`, міграції, тести, composer.json

**Час до live:** ~7-12 хвилин після merge у `main`

```
merge → CI: lint (1-2 хв) → test (2-4 хв) → build api:latest (2-3 хв) → deploy Droplet (1 хв)
                                                                                │
                                                                   Droplet оновлено
                                                                   
                                              Паралельно (≤5 хв після CI push):
                                              Watchtower на Mac mini:
                                                docker pull api:latest
                                                docker stop/start worker
                                              Mac mini worker оновлено
```

**Не потрібно:** ssh, scp, `docker compose restart` нічого.

**Особливість міграцій:** CI deploy job виконує `doctrine:migrations:migrate --no-interaction` на Droplet автоматично. На Mac mini worker — міграція не потрібна (worker тільки читає/пише, не ініціює схему).

---

## Сценарій 2: Зміна Playwright сервісу (docker/playwright/)

**Що змінюєш:** `docker/playwright/server.js`, `package.json`, `Dockerfile`

**Час до live:** ~7-12 хвилин (CI build) + ≤5 хв (Watchtower) = ≤17 хв після merge

```
merge → CI build playwright-equeue:latest → GHCR
                                              │
                               (≤5 хв, Watchtower poll)
                               ▼
                  Mac mini Watchtower:
                    docker pull playwright-equeue:latest
                    docker stop programel-playwright-equeue-1
                    docker run ... (новий image, той самий порт 3001)
                  
                  playwright-equeue оновлено без downtime*
```

> *Downtime ≈ кілька секунд поки старий контейнер зупиняється і новий стартує. Worker під час цього може отримати помилку від `/slots` — обробляється як http_error, не фатально.

**Для локального дебагу playwright (без push):**

```bash
# Скопіювати override (якщо ще нема)
cp docker-compose.override.yml.example docker-compose.override.yml

# Після зміни server.js — rebuild і restart
docker compose build playwright-equeue
docker compose up -d playwright-equeue

# Тест
curl http://localhost:3001/health
curl http://localhost:3001/slots | jq .
```

`docker-compose.override.yml` у `.gitignore` — не потрапить у PR.

---

## Сценарій 3: Зміна docker-compose.home.yml

**Що змінюєш:** нова змінна оточення у `worker`, новий сервіс, зміна `depends_on`, нова volume

**Час до live на Mac mini:** ≤10 хвилин (CI + systemd timer poll)

```
merge → commit у main
             │
        (≤5 хв, systemd timer)
        ▼
Mac mini systemd → deploy-home.sh:
  git pull --ff-only origin main
  docker compose -f docker-compose.home.yml pull
  docker compose -f docker-compose.home.yml up -d
  # compose up -d перечитує compose file → recreate лише змінені сервіси
```

**Приклад:** додаєш `NEW_API_KEY: http://some-service` у `worker.environment`:
1. Зміни у `docker-compose.home.yml`
2. PR → merge
3. Через ≤10 хв `deploy-home.sh` тягне зміни і `docker compose up -d` recreate worker з новою env var
4. `docker exec programel-worker-1 printenv NEW_API_KEY` → бачиш нове значення

> **Увага:** якщо env var містить **секретне значення**, воно не може бути у compose-файлі (git). Тоді: значення додаєш вручну до `.env` на Mac mini, а у compose посилаєшся через `env_file: .env`. Зміна у compose (рядок env_file) деплоїться автоматично, секретне значення у `.env` — вручну один раз.

---

## Сценарій 4: Зміна nginx конфігу або docker-compose.prod.yml (Droplet)

**Що змінюєш:** `docker/nginx/**`, `docker-compose.prod.yml`

**Час до live на Droplet:** ~7 хвилин (CI deploy job scp + ssh)

```
merge → CI deploy job:
  scp docker-compose.prod.yml docker/nginx/ → /var/www/programel/
  ssh: docker compose pull && up -d && nginx -s reload
```

**Mac mini не зачіпається.** Compose файл для Mac mini — окремий (`docker-compose.home.yml`).

---

## Сценарій 5: Тільки тести / linting (PR без merge у main)

**Що деплоїться:** нічого. CI запускає lint + test але не build і не deploy (`if: github.ref == 'refs/heads/main'` у jobs `build` і `deploy`).

---

## Сценарій 6: Додавання нового сервісу на Mac mini

Наприклад, додаєш `redis-local` сервіс у `docker-compose.home.yml`.

1. Додай у `docker-compose.home.yml`:
   ```yaml
   redis-local:
     image: redis:7-alpine
     restart: unless-stopped
   ```
2. PR → merge
3. Через ≤10 хв `deploy-home.sh` виконає `docker compose up -d` → новий сервіс стартує

---

## Сценарій 7: Hotfix (потрібно прискорити деплой)

Якщо не хочеш чекати ≤5 хв Watchtower/timer polling:

**Запустити deploy-home.sh вручну:**
```bash
ssh serg@192.168.2.140 'sudo systemctl start programel-deploy.service'
# або напряму:
ssh serg@192.168.2.140 '/home/serg/programel/scripts/deploy-home.sh'
```

**Примусово оновити конкретний image через Watchtower:**
```bash
ssh serg@192.168.2.140 'docker exec programel-watchtower-1 /watchtower --run-once'
```

---

## Що НЕ автоматизовано (і чому)

| Дія                            | Чому не автоматизована                                                      | Як робити                                                                                   |
|--------------------------------|-----------------------------------------------------------------------------|---------------------------------------------------------------------------------------------|
| Оновлення `.env` (секрети)     | Секрети не зберігаються у git за дизайном                                   | Вручну `nano /home/serg/programel/.env` + `docker compose up -d --force-recreate <service>` |
| SSH тунель (autossh) якщо впав | autossh systemd service повинен рестартити сам (`Restart=always`)           | `sudo systemctl restart programel-tunnel.service`                                           |
| Перший bootstrap нової машини  | One-time setup, описаний у [02-ubuntu-bootstrap.md](02-ubuntu-bootstrap.md) | По чеклісту                                                                                 |
| Оновлення самого Watchtower    | containrrr/watchtower image не оновлюється сам собою за замовчуванням       | `docker compose pull watchtower && docker compose up -d watchtower`                         |
