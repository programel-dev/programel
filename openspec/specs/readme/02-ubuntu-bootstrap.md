# Ubuntu Server Bootstrap (Mac mini) — One-Time Setup

Виконується **один раз** на чистій або існуючій Ubuntu Server машині. Після цього — повна автоматизація.

Припущення:
- Ubuntu 22.04 / 24.04 LTS
- Користувач: `serg` (з sudo)
- Домашній каталог: `/home/serg`
- Репозиторій буде у: `/home/serg/programel`

---

## 0. Базова підготовка системи

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y git curl ca-certificates gnupg lsb-release
```

---

## 1. Встановлення Docker

```bash
# Додати Docker GPG key і репозиторій
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# Додати serg до групи docker (щоб не писати sudo кожен раз)
sudo usermod -aG docker serg

# Застосувати групу БЕЗ logout (або перелогінитись)
newgrp docker

# Перевірка
docker run --rm hello-world
docker compose version
```

---

## 2. Клонування репозиторію

```bash
# Якщо репозиторій приватний — треба SSH ключ або GitHub token
# Варіант A: SSH ключ (рекомендовано)
ssh-keygen -t ed25519 -C "mac-mini-deploy" -f ~/.ssh/github_deploy -N ""
cat ~/.ssh/github_deploy.pub
# → Додати цей pub key до GitHub: Settings → SSH and GPG keys → New SSH key
#   Або до репозиторію: Settings → Deploy keys → Add deploy key (read-only достатньо)

# Налаштувати SSH конфіг
cat >> ~/.ssh/config <<'EOF'
Host github.com
    IdentityFile ~/.ssh/github_deploy
    StrictHostKeyChecking accept-new
EOF

# Клонувати
git clone git@github.com:programel-dev/programel.git /home/serg/programel

# Перевірка
cd /home/serg/programel && git log --oneline -3
```

---

## 3. Аутентифікація Docker у GHCR

Потрібна для `docker pull ghcr.io/programel-dev/programel/*` (приватні images).

```bash
# Створити GitHub Personal Access Token:
# GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic)
# Scopes: read:packages  (тільки читання, достатньо)
# Або Fine-grained token: Contents=read, Packages=read

# Зберегти токен у змінну (не зберігається у history)
read -s GITHUB_TOKEN
# (вводиш токен і Enter)

# Логін
echo "$GITHUB_TOKEN" | docker login ghcr.io -u <твій-github-username> --password-stdin

# Перевірка — спробувати стягнути image
docker pull ghcr.io/programel-dev/programel/api:latest
# Очікуємо: Pull complete або Already up to date

# Токен зберігся у ~/.docker/config.json — Watchtower його теж використовує
cat ~/.docker/config.json | grep ghcr.io
```

> **Важливо:** якщо запускаєш docker compose від `root` (наприклад через `sudo`), то `~/.docker/config.json` — це `/root/.docker/config.json`. Переконайся що логін виконаний тим самим користувачем, від якого запускається compose.

---

## 4. Налаштування .env

```bash
cd /home/serg/programel

# Скопіювати шаблон і заповнити секрети
cp .env.example .env   # або вручну create, якщо .env.example немає
nano .env
```

Мінімально необхідні змінні для worker:

```dotenv
APP_ENV=prod
APP_SECRET=<random_32_char_string>

DATABASE_URL=postgresql://programel:<password>@host.docker.internal:5432/programel
MESSENGER_TRANSPORT_DSN=redis://:<password>@host.docker.internal:6379/messages

PLAYWRIGHT_EQUEUE_URL=http://playwright-equeue:3001
FLARESOLVERR_URL=http://flaresolverr:8191
EQUEUE_TARGET_URL=https://munich.pasport.org.ua/solutions/e-queue

TELEGRAM_BOT_TOKEN=<bot_token>
TELEGRAM_CHAT_ID=<chat_id>
```

> Переконайся що `host.docker.internal` резолвиться до host gateway — у `docker-compose.home.yml` є `extra_hosts: host.docker.internal:host-gateway`.

---

## 5. Перший запуск контейнерів

```bash
cd /home/serg/programel

# Стягнути всі images
docker compose -f docker-compose.home.yml pull

# Запустити
docker compose -f docker-compose.home.yml up -d

# Перевірити стан
docker compose -f docker-compose.home.yml ps

# Лог worker (перші 50 рядків)
docker logs --tail 50 programel-worker-1

# Лог playwright-equeue
docker logs --tail 50 programel-playwright-equeue-1
curl http://localhost:3001/health
# → {"status":"ok"}
```

---

## 6. Налаштування systemd timer (замість launchd — це Ubuntu)

Цей timer замінює launchd на macOS. Він запускає `scripts/deploy-home.sh` кожні 5 хвилин і синкає `docker-compose.home.yml` зміни.

### 6.1. Створити systemd service unit

```bash
sudo nano /etc/systemd/system/programel-deploy.service
```

Вміст:

```ini
[Unit]
Description=Programel home deploy sync
After=network-online.target docker.service
Requires=docker.service

[Service]
Type=oneshot
User=serg
Group=serg
WorkingDirectory=/home/serg/programel
ExecStart=/home/serg/programel/scripts/deploy-home.sh
StandardOutput=journal
StandardError=journal
SyslogIdentifier=programel-deploy
# Не завалює систему якщо скрипт повернув non-zero
SuccessExitStatus=0 1
```

### 6.2. Створити systemd timer unit

```bash
sudo nano /etc/systemd/system/programel-deploy.timer
```

Вміст:

```ini
[Unit]
Description=Run programel home deploy sync every 5 minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
AccuracySec=30s

[Install]
WantedBy=timers.target
```

### 6.3. Активувати та запустити

```bash
sudo systemctl daemon-reload

# Увімкнути + стартувати timer (автозапуск після reboot)
sudo systemctl enable --now programel-deploy.timer

# Перевірити timer
systemctl status programel-deploy.timer
systemctl list-timers programel-deploy.timer

# Запустити вручну для перевірки
sudo systemctl start programel-deploy.service

# Переглянути лог першого запуску
journalctl -u programel-deploy.service -n 50
```

---

## 7. Налаштування autossh тунелю (якщо ще не налаштовано)

Worker потребує доступу до Postgres і Redis на Droplet через SSH тунель.

```bash
sudo apt install -y autossh

# SSH ключ для тунелю (якщо ще немає)
ssh-keygen -t ed25519 -C "mac-mini-tunnel" -f ~/.ssh/tunnel_key -N ""
# Pub key треба додати до authorized_keys на Droplet

# Тест тунелю вручну
ssh -i ~/.ssh/tunnel_key -N \
  -L 0.0.0.0:5432:localhost:5432 \
  -L 0.0.0.0:6379:localhost:6379 \
  root@<droplet-ip>
# → Ctrl+C після перевірки

# Створити systemd service для autossh
sudo nano /etc/systemd/system/programel-tunnel.service
```

```ini
[Unit]
Description=SSH tunnel to programel droplet (postgres + redis)
After=network-online.target
Wants=network-online.target

[Service]
User=serg
ExecStart=/usr/bin/autossh -M 0 -N \
    -o "ServerAliveInterval=30" \
    -o "ServerAliveCountMax=3" \
    -o "ExitOnForwardFailure=yes" \
    -o "StrictHostKeyChecking=accept-new" \
    -i /home/serg/.ssh/tunnel_key \
    -L 0.0.0.0:5432:localhost:5432 \
    -L 0.0.0.0:6379:localhost:6379 \
    root@<droplet-ip>
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now programel-tunnel.service
systemctl status programel-tunnel.service

# Перевірити тунель
nc -z localhost 5432 && echo "postgres OK"
nc -z localhost 6379 && echo "redis OK"
```

---

## 8. Фінальна перевірка

```bash
# Всі 4 сервіси running
docker compose -f docker-compose.home.yml ps
# Очікуємо: playwright-equeue, flaresolverr, worker, watchtower — Up

# Worker не падає (жодного "Operation timed out" за останні 5 хв)
docker logs --since 5m programel-worker-1 2>&1 | grep -c "timed out" || true
# Очікуємо: 0

# playwright-equeue відповідає
curl http://localhost:3001/health
# → {"status":"ok"}

# systemd timer активний
systemctl is-active programel-deploy.timer
# → active

# Tunnel активний
systemctl is-active programel-tunnel.service
# → active

# Worker починає polling (через 5 хв після старту scheduler виконає PollEqueueCommand)
docker logs --tail 20 programel-worker-1 | grep -E "(equeue|playwright|Consuming)"
```

---

## Checklist після bootstrap

- [ ] Docker встановлено, `serg` у групі `docker`
- [ ] Репо склоновано у `/home/serg/programel`
- [ ] `docker login ghcr.io` виконано, токен збережено у `~/.docker/config.json`
- [ ] `.env` заповнено (DATABASE_URL, MESSENGER_TRANSPORT_DSN, TELEGRAM_BOT_TOKEN, ...)
- [ ] `docker compose pull && up -d` — всі 4 сервіси Running
- [ ] `curl http://localhost:3001/health` → `{"status":"ok"}`
- [ ] `systemd timer programel-deploy` active
- [ ] `systemd service programel-tunnel` active (якщо потрібен)
- [ ] Перший реальний poll через ~5 хв: `document_center.snapshot` таблиця має новий рядок
