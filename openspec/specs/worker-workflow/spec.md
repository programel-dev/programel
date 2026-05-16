# Workflow асинхронного воркера

Цей документ описує **повну роботу фонового воркера** — окремого Docker-контейнера, який відповідає за моніторинг equeue-сторінки Мюнхенського консульства і доставку Telegram-нотифікацій користувачам.

Воркер — це **не web-сервер**. Він нічого не відповідає на HTTP-запити. Його завдання:
1. Регулярно "ходити" на сторінку консульства через FlareSolverr (обхід Cloudflare)
2. Виявляти зміни стану ("є вільні місця / немає")
3. Розсилати Telegram-повідомлення підключеним користувачам тільки при зміні стану

---

## Загальна архітектура

```
┌─────────────────┐
│ Symfony         │  кожні EQUEUE_POLL_INTERVAL секунд (мін 60, дефолт 300)
│ Scheduler       │──────────────────────────────────────────────────────▶
│ (EqueueSchedule)│                                                       │
└─────────────────┘                                          PollEqueueMessage
                                                                           │
                                                                           ▼
                                              ┌────────────────────────────────────┐
                                              │  Redis stream "scheduler_default"  │
                                              └─────────────────┬──────────────────┘
                                                                │
                                                                │ consume
                                                                ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          Worker контейнер                                   │
│  bin/console messenger:consume async scheduler_default --memory-limit=128M  │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────┐       │
│  │                    PollEqueueHandler                            │       │
│  │  1. отримати Redis lock "equeue.poll" (TTL 120s)                │       │
│  │  2. FlareSolverrEqueueFetcher::fetch() → HTML                   │       │
│  │  3. зберегти EqueueRawHtml (rolling 8h buffer)                  │       │
│  │  4. detect alertPresent: str_contains($html, 'місця зайняті')   │       │
│  │  5. зберегти EqueueSnapshot                                     │       │
│  │  6. порівняти з попереднім snapshot → state transition?         │       │
│  │  7. якщо transition → dispatch BroadcastTelegramMessage         │       │
│  └──────────────────────────────────┬──────────────────────────────┘       │
│                                     │ (тільки при зміні стану)             │
│                                     ▼                                       │
│  ┌─────────────────────────────────────────────────────────────────┐       │
│  │               BroadcastTelegramHandler                          │       │
│  │  - findAllConnected() → список TelegramAccount.chatId           │       │
│  │  - для кожного dispatch SendTelegramMessage(chatId, text)       │       │
│  └──────────────────────────────────┬──────────────────────────────┘       │
│                                     │ (N повідомлень у чергу async)        │
│                    ┌────────────────┼────────────────┐                     │
│                    ▼                ▼                 ▼                     │
│  ┌──────────────────────────────────────────────────────────────────┐      │
│  │                 SendTelegramHandler × N                          │      │
│  │  - TelegramClient::sendMessage(chatId, text)                     │      │
│  │  - 403 → відв'язати chatId, unrecoverable                        │      │
│  │  - 429/5xx → retryable (exponential backoff)                     │      │
│  │  - success → записати messageId                                  │      │
│  └──────────────────────────────────────────────────────────────────┘      │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Де запускається воркер:**
- **Dev** — сервіс `worker` у `docker-compose.dev.yml`
- **Staging** — сервіс `worker-staging` у `docker-compose.staging.yml`, `restart: unless-stopped`, `--time-limit=3600` (контейнер рестартує раз на годину для профілактики memory bloat)
- **Prod** — **відсутній у prod compose-файлі**, запускається окремо на home-сервері (переміщено з prod комітом `ad20a7b`)

---

## Черги і транспорти

```
Redis streams:
  messages           ← основна черга async
  messages_failed    ← DLQ (після вичерпання retry)
  scheduler_default  ← авто-створюється Symfony Scheduler
```

**Routing:** усі `App\Message\*` → `async`. Коли Scheduler емітить `PollEqueueMessage` — вона також маршрутизується в `async`.

**Worker слухає обидва транспорти** одночасно: `async` (для всіх message handlers) і `scheduler_default` (для Scheduler).

---

## ADDED Requirements

### Requirement: Регулярний polling через Scheduler
Система МАЄ запускати перевірку equeue-сторінки з фіксованим інтервалом через Symfony Scheduler, без потреби у зовнішньому cron чи ручному запуску.

#### Scenario: Scheduler емітить PollEqueueMessage з налаштованим інтервалом
- **GIVEN** env var `EQUEUE_POLL_INTERVAL` встановлено у `300` (5 хвилин)
- **AND** воркер запущений і споживає `scheduler_default`
- **WHEN** минає 300 секунд від попередньої емісії
- **THEN** Symfony Scheduler автоматично розміщує `PollEqueueMessage` у чергу `async`
- **AND** воркер отримує це повідомлення і запускає `PollEqueueHandler`

#### Scenario: Мінімальний інтервал polling — 60 секунд
- **GIVEN** `EQUEUE_POLL_INTERVAL` встановлено у `30` (менше за мінімум)
- **WHEN** `EqueueSchedule::getSchedule()` обчислює інтервал через `max(60, $this->pollInterval)`
- **THEN** polling відбувається кожні **60 секунд** (мінімум), а не 30
- **AND** значення у логах відображає 60с, а не 30с

#### Scenario: Воркер стартує і одразу готовий до роботи
- **GIVEN** команда `bin/console messenger:consume async scheduler_default --memory-limit=128M -vv`
- **WHEN** процес запускається
- **THEN** воркер входить у нескінченний loop очікування повідомлень
- **AND** при досягненні `--memory-limit=128M` процес завершується gracefully (Symfony Messenger автоматично перезапускає через supervisor або `restart: unless-stopped` в docker compose)

---

### Requirement: Захист від паралельного виконання (distributed lock)
Оскільки може бути запущено кілька воркер-процесів (наприклад, staging + home server), система МАЄ гарантувати, що тільки один `PollEqueueHandler` виконується одночасно через Redis-локер.

#### Scenario: Перший процес отримує lock і виконує polling
- **GIVEN** в Redis немає ключа `equeue.poll`
- **WHEN** `PollEqueueHandler` починає обробку `PollEqueueMessage`
- **THEN** handler отримує Redis lock `equeue.poll` з TTL 120 секунд
- **AND** виконує повний цикл: fetch → збереження → state detection → (опційно) broadcast
- **AND** після завершення відпускає lock

#### Scenario: Другий паралельний процес не заважає
- **GIVEN** інший worker-процес уже тримає lock `equeue.poll`
- **WHEN** другий `PollEqueueHandler` намагається отримати lock
- **THEN** він **не чекає** — одразу повертається без помилки (lock не acquireable)
- **AND** `PollEqueueMessage` вважається успішно обробленим (не йде на retry)
- **AND** у логах записується `debug: poll already in progress, skipping`

#### Scenario: Crash воркера не блокує наступний poll
- **GIVEN** voркер впав під час обробки (container kill, OOM), залишивши lock
- **AND** lock був виставлений з TTL 120 секунд
- **WHEN** минає 120 секунд
- **THEN** Redis автоматично видаляє застарілий lock
- **AND** наступний `PollEqueueMessage` виконується нормально

---

### Requirement: Fetch HTML сторінки через FlareSolverr
Система МАЄ отримувати HTML equeue-сторінки через FlareSolverr — проксі на основі headless Chrome, який вирішує Cloudflare challenge і повертає реальний контент.

#### Scenario: Успішний fetch повертає HTML
- **GIVEN** FlareSolverr доступний на `FLARESOLVERR_URL` (дефолт `http://flaresolverr:8191/v1`)
- **WHEN** `FlareSolverrEqueueFetcher::fetch()` відправляє POST:
  ```json
  { "cmd": "request.get", "url": "<EQUEUE_TARGET_URL>", "maxTimeout": 30000 }
  ```
- **THEN** FlareSolverr повертає відповідь:
  ```json
  { "status": "ok", "solution": { "status": 200, "response": "<html>...</html>" } }
  ```
- **AND** `solution.response` має довжину >= 1000 байт
- **AND** fetcher повертає `EqueueRawResponse(httpStatus: 200, body: "<html>...", fetchedAt: now())`

#### Scenario: Відповідь менше 1000 байт — treated як block page
- **GIVEN** FlareSolverr повертає відповідь з `solution.response` довжиною < 1000 байт (наприклад, Cloudflare виклик не вирішено, повернуто заглушку)
- **WHEN** fetcher перевіряє `strlen($body) < 1000`
- **THEN** fetcher повертає `EqueueRawResponse(httpStatus: 0, body: "...", fetchedAt: now())`
- **AND** `PollEqueueHandler` трактує httpStatus=0 як помилку fetch
- **AND** зберігає `EqueueSnapshot` зі `STATUS_HTTP_ERROR`

#### Scenario: FlareSolverr timeout
- **GIVEN** FlareSolverr не відповідає протягом 35 секунд (PHP timeout) або повертає `status != "ok"`
- **WHEN** fetcher не отримує валідну відповідь
- **THEN** fetcher повертає `EqueueRawResponse(httpStatus: 0, ...)`
- **AND** `PollEqueueHandler` обробляє це як failure (div. Requirement нижче)

#### Scenario: FlareSolverr контейнер недоступний (network error)
- **GIVEN** FlareSolverr контейнер не запущений або мережа недоступна
- **WHEN** HTTP-клієнт кидає `TransportException`
- **THEN** виняток проходить у Symfony Messenger retry pipeline
- **AND** повідомлення `PollEqueueMessage` перезапланується з backoff
- **AND** після 3 невдалих спроб — потрапляє в DLQ (`messages_failed`)

---

### Requirement: Збереження результатів polling
Система МАЄ зберігати кожен результат polling у БД — і повний HTML (для діагностики), і метадані (для state detection).

#### Scenario: Збереження повного HTML у rolling buffer
- **GIVEN** успішний fetch повернув HTML довжиною >= 1000 байт
- **WHEN** `PollEqueueHandler` зберігає результат
- **THEN** у таблиці `equeue_raw_html` створюється новий рядок з повним HTML body і `fetchedAt = now()`
- **AND** видаляються всі рядки з `fetchedAt < now() - 8 годин` (rolling buffer, ~96 рядків при 5-хвилинному інтервалі)

#### Scenario: Збереження snapshot метаданих
- **GIVEN** будь-який результат fetch (success або failure)
- **WHEN** `PollEqueueHandler` зберігає snapshot
- **THEN** у таблиці `equeue_snapshot` створюється рядок:
  - `httpStatus` — HTTP код відповіді (200 або 0 при помилці)
  - `alertPresent` — чи присутній текст "Наразі всі місця зайняті" (тільки при успішному fetch)
  - `fetchedAt = now()`
  - `payload` — JSON з `{slots: []}` (парсинг слотів ще не реалізований, порожній масив)

#### Scenario: Детекція alert через пошук підрядка
- **GIVEN** fetch успішний і HTML тіло доступне
- **WHEN** `PollEqueueHandler` перевіряє HTML
- **THEN** `alertPresent = str_contains($html, 'Наразі всі місця зайняті')`
- **AND** якщо рядок присутній — `alertPresent = true` (місць **немає**)
- **AND** якщо рядок відсутній — `alertPresent = false` (місця **можливо є**)

---

### Requirement: State machine — нотифікація тільки при зміні стану
Система МАЄ відправляти Telegram-нотифікації виключно при **переходах** між станами, а не при кожному polling. Це запобігає спаму повідомленнями кожні 5 хвилин.

#### Scenario: Перехід "заняти → вільні" — dispatch broadcast
- **GIVEN** останній збережений `EqueueSnapshot` у БД має `alertPresent = true` (місць не було)
- **WHEN** новий polling повертає HTML без рядка "місця зайняті" (`alertPresent = false`)
- **THEN** `PollEqueueHandler` виявляє зміну стану: `true → false`
- **AND** dispatch `BroadcastTelegramMessage` з текстом типу "⚡️ Вейкап Нео, стан змінився! Перевір сайт консульства."
- **AND** зберігає новий snapshot з `alertPresent = false`

#### Scenario: Стан не змінився — нотифікація не відправляється
- **GIVEN** останній snapshot має `alertPresent = true`
- **WHEN** новий polling теж повертає `alertPresent = true`
- **THEN** `PollEqueueHandler` **не** dispatch жодних повідомлень
- **AND** просто зберігає черговий snapshot і завершується мовчки

#### Scenario: Перехід "вільні → заняти" — нотифікація НЕ відправляється
- **GIVEN** останній snapshot має `alertPresent = false` (місця були вільні)
- **WHEN** новий polling повертає `alertPresent = true` (місця знову зайняті)
- **THEN** `PollEqueueHandler` **не** dispatch нотифікацію (зворотній перехід не нотифікується)
- **AND** просто зберігає snapshot

> **Обґрунтування:** Ціль системи — сповістити юзера що з'явились місця. Повернення до стану "немає місць" — це сумна, але не actionable інформація. Щоб не спамити туди-сюди при нестабільному стані на сайті — нотифікується тільки поява місць.

#### Scenario: Перша помилка fetch після серії успішних
- **GIVEN** останній snapshot має `httpStatus = 200` (fetch був успішним)
- **WHEN** новий fetch повертає `httpStatus = 0` (timeout або block page)
- **THEN** `PollEqueueHandler` зберігає snapshot з `STATUS_HTTP_ERROR`
- **AND** dispatch `BroadcastTelegramMessage` з текстом про помилку: "🚨 Не вдалося перевірити чергу, щось пішло не так"
- **AND** це сигнал для юзера: "система не працює, можливо пропустимо момент"

#### Scenario: Послідовні помилки fetch — не спамити
- **GIVEN** кілька останніх snapshot підряд мають `httpStatus = 0`
- **WHEN** новий fetch знову повертає `httpStatus = 0`
- **THEN** `PollEqueueHandler` зберігає snapshot з помилкою **мовчки** — без broadcast
- **AND** broadcast відправляється тільки один раз (при першому переході в стан помилки)

#### Scenario: Відновлення після серії помилок
- **GIVEN** останній snapshot має `STATUS_HTTP_ERROR`
- **WHEN** новий fetch успішний (`httpStatus = 200`, `alertPresent = true`)
- **THEN** стан відновлено, snapshot зберігається з актуальними даними
- **AND** нотифікація **не** відправляється (alert досі присутній — місць немає)
- **AND** якщо новий fetch успішний і `alertPresent = false` — тоді dispatch broadcast про вільні місця

---

### Requirement: Broadcast fan-out до всіх підключених користувачів
При виявленні зміни стану система МАЄ надіслати повідомлення **всім** користувачам, які підключили Telegram, через масову диспетчеризацію окремих повідомлень на кожного.

#### Scenario: Broadcast відправляється всім connected юзерам
- **GIVEN** у БД є 5 записів `telegram_account` з непустим `chat_id` (connected)
- **AND** є 3 записи з `chat_id = null` (не підключені)
- **WHEN** `BroadcastTelegramHandler` обробляє `BroadcastTelegramMessage`
- **THEN** handler викликає `TelegramAccountRepository::findAllConnected()` → 5 акаунтів
- **AND** для кожного з 5 dispatch `SendTelegramMessage(chatId: "...", text: "...")`
- **AND** усі 5 повідомлень потрапляють у чергу `async` незалежно одне від одного

#### Scenario: Немає connected юзерів — нічого не відправляється
- **GIVEN** `findAllConnected()` повертає порожній масив (нікому не підключений Telegram)
- **WHEN** `BroadcastTelegramHandler` обробляє `BroadcastTelegramMessage`
- **THEN** жодного `SendTelegramMessage` не dispatch
- **AND** handler завершується успішно (не помилка)

#### Scenario: Незалежна обробка — fail одного не зупиняє інших
- **GIVEN** dispatch 5 `SendTelegramMessage` у чергу
- **WHEN** перше повідомлення для `chatId_1` завершується помилкою (наприклад, Telegram 5xx)
- **THEN** помилка потрапляє у retry pipeline **тільки для** `SendTelegramMessage(chatId_1)`
- **AND** повідомлення для `chatId_2`, `chatId_3`, `chatId_4`, `chatId_5` обробляються незалежно

---

### Requirement: Доставка Telegram-повідомлення і обробка помилок
Система МАЄ надійно доставляти окреме повідомлення в Telegram-чат, коректно розрізняти тимчасові та постійні помилки, і автоматично відв'язувати акаунти які заблокували бота.

#### Scenario: Успішна доставка
- **GIVEN** `SendTelegramHandler` отримує `SendTelegramMessage(chatId: "123456789", text: "...", notificationId: 42)`
- **WHEN** `TelegramClient::sendMessage("123456789", "...")` повертає успіх з `messageId: 987`
- **THEN** handler завершується успішно
- **AND** якщо `notificationId` є — в таблиці `equeue_notification[42]` записується `telegram_message_id = 987`

#### Scenario: Бот заблокований юзером (403) — відв'язати і не retry
- **GIVEN** юзер заблокував Telegram-бота (натиснув "Зупинити бота")
- **WHEN** Telegram API повертає HTTP 403 на спробу надіслати повідомлення
- **THEN** handler:
  1. Знаходить `TelegramAccount` за `chatId`
  2. Виставляє `chatId = null` (відв'язує акаунт)
  3. Зберігає зміну в БД
  4. Кидає `UnrecoverableMessageHandlingException`
- **AND** Symfony Messenger **не** ретраює повідомлення (unrecoverable)
- **AND** повідомлення потрапляє в DLQ без retry
- **AND** наступний broadcast цього юзера не отримає (chatId вже null)

#### Scenario: Telegram rate limit (429) — ретраювати з backoff
- **WHEN** Telegram API повертає HTTP 429 (Too Many Requests)
- **AND** `TelegramApiException` має `retryable = true`
- **THEN** handler rethrows виняток
- **AND** Symfony Messenger ставить повідомлення на retry: 1с → 4с → 16с

#### Scenario: Тимчасова помилка Telegram (5xx) — ретраювати
- **WHEN** Telegram API повертає HTTP 500 або 502
- **AND** `TelegramApiException` має `retryable = true`
- **THEN** те саме що для 429 — retry з backoff

#### Scenario: Невідновлювана помилка (інші 4xx)
- **WHEN** Telegram API повертає HTTP 400 (наприклад, невалідний chatId format)
- **AND** `TelegramApiException` має `retryable = false`
- **THEN** handler кидає `UnrecoverableMessageHandlingException`
- **AND** повідомлення в DLQ без retry

---

### Requirement: Retry strategy і Dead Letter Queue
Система МАЄ автоматично ретраювати тимчасово невдалі повідомлення з exponential backoff і зберігати невідновлювані збої в DLQ для ручної інспекції.

#### Scenario: Exponential backoff для тимчасових помилок
- **GIVEN** повідомлення у черзі `async` завершилося виключенням
- **AND** виключення — retryable (не `UnrecoverableMessageHandlingException`)
- **WHEN** Symfony Messenger отримує помилку
- **THEN** повідомлення перезапланується:
  - Спроба 1: затримка **1 секунда**
  - Спроба 2: затримка **4 секунди**
  - Спроба 3: затримка **16 секунд**
- **AND** після 3-ї невдалої спроби повідомлення переміщується у `failed` (DLQ)

#### Scenario: Повідомлення в DLQ доступне для інспекції
- **GIVEN** повідомлення потрапило в `messages_failed` після вичерпання retry
- **WHEN** розробник виконує `bin/console messenger:failed:show`
- **THEN** виводиться список повідомлень у DLQ з класами, причинами помилок і часом
- **AND** `bin/console messenger:failed:retry <id>` повторює конкретне повідомлення вручну
- **AND** `bin/console messenger:failed:remove <id>` видаляє повідомлення з DLQ

---

### Requirement: Dormant per-watch evaluation (не реалізовано)
Нижчеописаний flow існує в коді (`EvaluateWatchMessage` + `EvaluateWatchHandler`), але **ще не активований** — диспатч не відбувається. Зафіксовано в spec як майбутній намір.

#### [FUTURE] Scenario: Персональна нотифікація при match слоту і watch
- **GIVEN** `PollEqueueHandler` починає парсити слоти з `payload` (не реалізовано: наразі `payload.slots = []`)
- **AND** в БД є активні `EqueueWatch` підписки
- **WHEN** (майбутнє) у snapshot є слоти, один з яких матчиться з watch по `serviceCode` і `dateFrom ≤ slotDate ≤ dateTo`
- **THEN** (майбутнє) `PollEqueueHandler` dispatch `EvaluateWatchMessage(watchId, snapshotId)`
- **AND** `EvaluateWatchHandler`:
  1. Обчислює `SlotSignature::for(serviceCode, slotAt)` → SHA-256 хеш
  2. Перевіряє `EqueueNotificationRepository::existsBySignature(signature)` — дедуплікація
  3. Якщо не дублікат: зберігає `EqueueNotification`, dispatch `SendTelegramMessage` персонально до юзера
  4. Якщо дублікат — пропускає мовчки

> **Поточний production path:** замість per-watch матчингу система використовує broadcast до всіх connected users при будь-якій зміні alertPresent. Це спрощена версія до реалізації парсингу слотів.
