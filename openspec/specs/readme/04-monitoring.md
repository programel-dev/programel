# Моніторинг, логи, troubleshooting

## Швидкий статус — одна команда

```bash
ssh serg@192.168.2.140 '
echo "=== Docker containers ==="
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Image}}"

echo ""
echo "=== systemd timer ==="
systemctl status programel-deploy.timer --no-pager

echo ""
echo "=== tunnel ==="
systemctl is-active programel-tunnel.service && echo "tunnel: OK" || echo "tunnel: DEAD"
nc -z localhost 5432 && echo "postgres: reachable" || echo "postgres: UNREACHABLE"
nc -z localhost 6379 && echo "redis: reachable" || echo "redis: UNREACHABLE"

echo ""
echo "=== playwright health ==="
curl -sf http://localhost:3001/health || echo "FAIL"
'
```

---

## Логи по сервісах

### Worker (Symfony Messenger)

```bash
# Останні 50 рядків
docker logs --tail 50 programel-worker-1

# Лайвстрім
docker logs -f programel-worker-1

# Тільки помилки
docker logs programel-worker-1 2>&1 | grep -E "(ERROR|CRITICAL|Exception)"

# Перевірити що equeue polling працює
docker logs programel-worker-1 2>&1 | grep -i "equeue" | tail -20
```

### Playwright-equeue

```bash
# Логи сервера
docker logs --tail 50 programel-playwright-equeue-1

# Ручний тест slots endpoint
curl -s http://localhost:3001/slots | jq .
# Очікуємо: {"success":true,"slots":[],"fetchedAt":"..."} або slots з даними

# Якщо success:false з reason:blocked — Cloudflare заблокував
# Якщо success:false з reason:timeout — сторінка не завантажилась за 30 с
```

### Watchtower

```bash
# Що Watchtower оновлював
docker logs programel-watchtower-1 2>&1 | grep -E "(Found|Updated|Pulling|Session)"

# Останні pull операції
docker logs --since 30m programel-watchtower-1
```

### deploy-home.sh (systemd)

```bash
# Останні 20 запусків
journalctl -u programel-deploy.service -n 100 --no-pager

# Лайвстрім (корисно під час тесту)
journalctl -u programel-deploy.service -f

# Коли наступний запуск timer
systemctl list-timers programel-deploy.timer
```

### SSH тунель

```bash
# Стан
systemctl status programel-tunnel.service

# Лог
journalctl -u programel-tunnel.service -n 50 --no-pager

# Перевірити чи тунель активний
ss -tlnp | grep -E "5432|6379"
# Очікуємо: LISTEN рядки для обох портів
```

---

## Стан поллінгу у БД (через Droplet)

```bash
# Останні 5 snapshot записів
ssh droplet-programel 'docker exec programel-postgres-1 psql -U programel -d programel -c "
SELECT
    fetched_at,
    status,
    http_status,
    parser_version,
    payload->'"'"'slots'"'"' AS slots
FROM equeue_snapshot
ORDER BY fetched_at DESC
LIMIT 5;
"'

# Перевірити що поллінг живий (рядки за останні 10 хв)
ssh droplet-programel 'docker exec programel-postgres-1 psql -U programel -d programel -c "
SELECT COUNT(*), MAX(fetched_at)
FROM equeue_snapshot
WHERE fetched_at > NOW() - INTERVAL '"'"'10 minutes'"'"';
"'
# Очікуємо: count >= 1, max свіже
```

---

## Troubleshooting

### Worker падає з "Operation timed out" (Redis)

**Симптом:** `docker logs programel-worker-1` показує `RedisException: Operation timed out` кожні ~90 секунд.

**Причина:** SSH тунель дропає idle TCP з'єднання. PhpRedis під час `XREADGROUP BLOCK` тримає з'єднання відкритим — NAT/firewall вирізає idle тунель.

**Виправлення:**

1. Перевірити що тунель живий: `systemctl status programel-tunnel.service`
2. Перевірити TCP keepalive у `MESSENGER_TRANSPORT_DSN`:
   ```bash
   grep MESSENGER_TRANSPORT_DSN /home/serg/programel/.env
   # Повинно містити: ?tcp_keepalive=30&read_timeout=120
   ```
3. Якщо параметрів немає — додати:
   ```bash
   nano /home/serg/programel/.env
   # Знайти MESSENGER_TRANSPORT_DSN і додати до кінця URL:
   # ?tcp_keepalive=30&read_timeout=120
   docker compose -f docker-compose.home.yml up -d --force-recreate worker
   ```

---

### Watchtower не оновлює image

**Перевірити:**

```bash
# Чи є label на контейнері
docker inspect programel-playwright-equeue-1 \
  --format '{{index .Config.Labels "com.centurylinklabs.watchtower.enable"}}'
# Очікуємо: true

# Чи Docker аутентифікований у GHCR
docker pull ghcr.io/programel-dev/programel/playwright-equeue:latest
# Якщо "unauthorized" → перелогінитись:
echo "$GITHUB_TOKEN" | docker login ghcr.io -u <username> --password-stdin

# Лог Watchtower — чи є помилки аутентифікації
docker logs programel-watchtower-1 2>&1 | grep -i "error\|auth\|unauthorized"
```

---

### deploy-home.sh не синкає

**Перевірити:**

```bash
# Timer взагалі активний?
systemctl is-active programel-deploy.timer
# Якщо inactive → sudo systemctl start programel-deploy.timer

# Запустити вручну і подивитись output
sudo systemctl start programel-deploy.service
journalctl -u programel-deploy.service -n 30 --no-pager

# Найчастіша причина failure: git pull конфлікт або detached HEAD
cd /home/serg/programel
git status
git log --oneline -3
git fetch origin main
git diff HEAD origin/main --name-only
# Якщо є розбіжність — вирішити конфлікт або hard reset:
# git reset --hard origin/main  ← тільки якщо впевнений що ручних змін немає
```

---

### playwright-equeue повертає `{"success":false,"reason":"blocked"}`

**Симптом:** всі або більшість polls повертають `blocked`, worker логує `playwright scraper returned failure`.

**Це нормально (≤20% polls):** Cloudflare блокує headless Chromium навіть із stealth plugin. При наступному poll — зазвичай OK.

**Ненормально (>50% polls blocked):** Cloudflare посилила захист.

**Що робити:**
1. Відкрити https://munich.pasport.org.ua/solutions/e-queue у звичайному браузері з тієї ж IP — якщо бачиш "Just a moment..." — IP тимчасово заблокований.
2. Почекати 30-60 хвилин.
3. Якщо не відновлюється — розглянути збільшення interval між poll з 5 до 10+ хвилин (зміна у Symfony scheduler config).

---

### Новий сервіс у docker-compose.home.yml не стартує

**Перевірити:**

```bash
# Чи compose file оновився
cd /home/serg/programel && git log --oneline -3

# Спробувати вручну
docker compose -f docker-compose.home.yml up -d
docker compose -f docker-compose.home.yml ps

# Якщо error "image not found"
docker compose -f docker-compose.home.yml pull <service-name>
```

---

### Перевірити що GHCR image оновився після CI

```bash
# З dev машини
gh api /orgs/programel-dev/packages/container/programel%2Fplaywright-equeue/versions \
  --jq '.[0] | {created_at, tags: .metadata.container.tags}'

# Переконатись що :latest tag є і created_at відповідає часу останнього merge
```

---

## Корисні alias (додати у ~/.bashrc на Mac mini)

```bash
alias dps='docker ps --format "table {{.Names}}\t{{.Status}}\t{{.RunningFor}}"'
alias dlog='docker logs --tail 50 -f'
alias programel-status='docker compose -f /home/serg/programel/docker-compose.home.yml ps'
alias deploy-now='sudo systemctl start programel-deploy.service && journalctl -u programel-deploy.service -f'
```
