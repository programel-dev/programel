# Workflow підключення Telegram

Цей документ описує **повний шлях підключення Telegram-акаунта користувача** до системи: від моменту, коли користувач натискає "Підключити Telegram" у веб-інтерфейсі, до моменту, коли він починає отримувати нотифікації від бота про вільні слоти equeue.

Підключення працює через **одноразовий токен** (`connect_token`) і **deep-link на Telegram бота**. Це класична схема "магічного посилання" — користувач не вводить нічого в боті, просто натискає посилання, бот ловить `/start <token>` і викликає наш webhook, який зв'язує `chat_id` Telegram з нашим `User`.

---

## Загальна картина

```
┌─────────┐         ┌──────────┐         ┌──────────────┐         ┌───────────┐
│Користувач│        │   API    │         │ Telegram бот │         │ Telegram  │
│(браузер) │        │ (наш)    │         │ (наш polling)│         │ серверс   │
└────┬────┘         └────┬─────┘         └──────┬───────┘         └─────┬─────┘
     │                   │                      │                       │
     │ 1. Натиск         │                      │                       │
     │    "Підключити    │                      │                       │
     │     Telegram"     │                      │                       │
     │                   │                      │                       │
     │ 2. POST /telegram/connect-link            │                       │
     │──────────────────▶│                      │                       │
     │                   │                      │                       │
     │                   │ генерує connect_token (UUID), TTL 15 хв      │
     │                   │ зберігає в БД (TelegramAccount.connectToken) │
     │                   │                      │                       │
     │ 3. {url, expiresAt}                      │                       │
     │   url: t.me/bot?start=<token>            │                       │
     │◀──────────────────│                      │                       │
     │                   │                      │                       │
     │ 4. клікає url     │                      │                       │
     │─────────────────────────────────────────────────────────────────▶│
     │                   │                      │                       │
     │                   │                      │   /start <token>      │
     │                   │                      │◀──────────────────────│
     │                   │                      │                       │
     │                   │ POST /telegram/webhook/{secret}              │
     │                   │ {chat_id, connect_token}                     │
     │                   │◀─────────────────────│                       │
     │                   │                      │                       │
     │                   │ знаходить юзера за connect_token             │
     │                   │ зв'язує chat_id, занулює connect_token       │
     │                   │                      │                       │
     │                   │ 200 OK               │                       │
     │                   │─────────────────────▶│                       │
     │                   │                      │                       │
     │                   │                      │  "✅ Підключено"      │
     │                   │                      │──────────────────────▶│
     │                   │                      │                       │
     │ 5. GET /telegram/status                  │                       │
     │──────────────────▶│                      │                       │
     │                   │                      │                       │
     │ {connected: true, chatId: ...}           │                       │
     │◀──────────────────│                      │                       │
```

**Сутність `TelegramAccount`:**
- `id` — int
- `user` — OneToOne з `User` (один Telegram акаунт на одного юзера, унікальний)
- `chatId` — string|null, ID чату в Telegram (виставляється webhook'ом). До підключення — `null`.
- `connectToken` — string|null, одноразовий токен для лінкування (видається API, занулюється webhook'ом)
- `connectTokenExpiresAt` — datetime|null, термін дії токена (15 хв від генерації)
- `connectedAt` — datetime|null, момент успішного підключення
- `createdAt`, `updatedAt` — технічні поля

**Стани:**
1. **Не підключено** — `chatId = null`, `connectToken = null` (або токен протерміновано)
2. **Очікує підключення** — `chatId = null`, `connectToken = "..."`, `connectTokenExpiresAt > now()`
3. **Підключено** — `chatId = "..."`, `connectedAt != null`, `connectToken = null`

**Endpoint'и:**
- `POST /api/v1/telegram/connect-link` — генерує deep-link, потрібен JWT
- `GET /api/v1/telegram/status` — повертає поточний стан підключення, потрібен JWT
- `POST /api/v1/telegram/webhook/{secret}` — приймає підтвердження від бота, НЕ потребує JWT (захищений секретом в URL)

---

## ADDED Requirements

### Requirement: Генерація deep-link для підключення Telegram
Аутентифікований користувач МАЄ змогу запитати посилання на Telegram-бота через `POST /api/v1/telegram/connect-link`. Це посилання містить одноразовий токен, який бот передасть назад до API для лінкування.

#### Scenario: Перша генерація link для нового користувача
- **GIVEN** користувач `Alice` залогінений
- **AND** у `Alice` немає запису в таблиці `telegram_account`
- **WHEN** клієнт надсилає `POST /api/v1/telegram/connect-link` з JWT `Alice`
- **THEN** сервер:
  1. Створює новий рядок `telegram_account` для `Alice`
  2. Генерує UUID v4 як `connect_token`
  3. Виставляє `connect_token_expires_at = now() + 15 хвилин`
  4. Зберігає в БД
- **AND** відповідає `HTTP 200` з тілом:
  ```json
  {
    "url": "https://t.me/programel_bot?start=550e8400-e29b-41d4-a716-446655440000",
    "expiresAt": "2026-05-15T10:45:00+00:00"
  }
  ```
- **AND** `expiresAt` — рівно 15 хвилин від часу запиту в форматі ISO 8601 з таймзоною

#### Scenario: Повторна генерація link до закінчення TTL
- **GIVEN** `Alice` уже має `telegram_account` з валідним `connect_token` (виданим 5 хвилин тому, ще не використаним)
- **WHEN** `Alice` ще раз надсилає `POST /api/v1/telegram/connect-link`
- **THEN** сервер генерує **новий** `connect_token` (старий стає невалідним)
- **AND** оновлює `connect_token_expires_at = now() + 15 хвилин`
- **AND** повертає новий URL
- **AND** старий URL більше не працює (якщо користувач його все ж натисне, бот отримає `/start <старий_токен>`, webhook отримає невалідний токен → 422)

#### Scenario: Генерація link для вже підключеного користувача
- **GIVEN** `Alice` уже підключила Telegram раніше (`chatId = "123456789"`, `connectedAt != null`)
- **WHEN** `Alice` надсилає `POST /api/v1/telegram/connect-link` (наприклад, хоче переприв'язати інший Telegram акаунт)
- **THEN** сервер генерує новий `connect_token`
- **AND** повертає `HTTP 200` з новим URL
- **AND** **існуюче підключення (`chatId`) залишається активним** доки новий webhook не прийде
- **AND** коли новий webhook прийде з іншим `chatId` — `chatId` перезаписується (переприв'язка)

#### Scenario: Запит link без авторизації
- **WHEN** клієнт надсилає `POST /api/v1/telegram/connect-link` без JWT
- **THEN** сервер відповідає `HTTP 401`

---

### Requirement: Перевірка стану підключення Telegram
Аутентифікований користувач МАЄ змогу через `GET /api/v1/telegram/status` дізнатися, чи його Telegram підключено, і за потреби отримати маскований `chatId`.

#### Scenario: Користувач, який ніколи не підключав Telegram
- **GIVEN** у `Alice` немає запису в `telegram_account`
- **WHEN** клієнт надсилає `GET /api/v1/telegram/status` з JWT `Alice`
- **THEN** сервер відповідає `HTTP 200` з тілом:
  ```json
  { "connected": false }
  ```

#### Scenario: Користувач у процесі підключення (link згенерований, але не пройдений)
- **GIVEN** `Alice` запросила link 5 хвилин тому, але ще не натиснула його
- **AND** `connect_token` ще валідний, `chatId = null`
- **WHEN** клієнт надсилає `GET /api/v1/telegram/status`
- **THEN** сервер відповідає `HTTP 200` з тілом:
  ```json
  {
    "connected": false,
    "pendingConnection": true,
    "pendingUntil": "2026-05-15T10:45:00+00:00"
  }
  ```
- **AND** фронтенд може показати "Очікуємо підтвердження в Telegram..."

#### Scenario: Користувач успішно підключив Telegram
- **GIVEN** `Alice` успішно пройшла процес підключення (webhook відпрацював)
- **AND** `chatId = "123456789"`, `connectedAt = "2026-05-10T14:00:00+00:00"`
- **WHEN** клієнт надсилає `GET /api/v1/telegram/status`
- **THEN** сервер відповідає `HTTP 200` з тілом:
  ```json
  {
    "connected": true,
    "chatId": "123456789",
    "connectedAt": "2026-05-10T14:00:00+00:00"
  }
  ```

#### Scenario: Запит статусу без авторизації
- **WHEN** клієнт надсилає `GET /api/v1/telegram/status` без JWT
- **THEN** сервер відповідає `HTTP 401`

---

### Requirement: Webhook для підтвердження підключення з боку Telegram-бота
Telegram-бот (внутрішній сервіс) МАЄ викликати `POST /api/v1/telegram/webhook/{secret}` після того, як отримав від користувача команду `/start <connect_token>`. URL містить shared secret в path — це примітивна, але достатня автентифікація для внутрішнього сервісу.

#### Scenario: Успішне підключення через webhook
- **GIVEN** `Alice` має `telegram_account` з `connect_token = "abc-123"`, не протермінованим
- **AND** `WEBHOOK_SECRET` env var встановлено в `"s3cr3t-xyz"`
- **WHEN** Telegram-бот надсилає `POST /api/v1/telegram/webhook/s3cr3t-xyz` з тілом:
  ```json
  {
    "chat_id": "123456789",
    "connect_token": "abc-123"
  }
  ```
- **THEN** сервер:
  1. Порівнює `{secret}` з URL із `WEBHOOK_SECRET` — збігається
  2. Знаходить `telegram_account` за `connect_token = "abc-123"`
  3. Виставляє `chat_id = "123456789"`, `connected_at = now()`
  4. Занулює `connect_token` і `connect_token_expires_at`
- **AND** відповідає `HTTP 200` з тілом:
  ```json
  { "ok": true, "userId": 1 }
  ```
- **AND** бот після цього надсилає користувачу повідомлення в Telegram: "✅ Підключення успішне"

#### Scenario: Webhook з невалідним секретом
- **WHEN** Telegram-бот (або зловмисник) надсилає `POST /api/v1/telegram/webhook/wrong-secret` з будь-яким тілом
- **THEN** сервер відповідає `HTTP 403 Forbidden`
- **AND** жодних змін у БД не відбувається
- **AND** інцидент може бути залогований (warn рівень) для моніторингу

#### Scenario: Webhook з невідомим connect_token
- **GIVEN** `WEBHOOK_SECRET` валідний
- **AND** жоден `telegram_account` не має `connect_token = "невідомий-токен"`
- **WHEN** Telegram-бот надсилає webhook з `"connect_token": "невідомий-токен"`
- **THEN** сервер відповідає `HTTP 422` з тілом:
  ```json
  { "error": "connect_token_invalid" }
  ```

#### Scenario: Webhook з протермінованим connect_token
- **GIVEN** `Alice` запросила link 20 хвилин тому (TTL 15 хв уже минув)
- **AND** `connect_token = "abc-123"` ще існує в БД, але `connect_token_expires_at < now()`
- **WHEN** Telegram-бот надсилає webhook з цим токеном
- **THEN** сервер відповідає `HTTP 422` з тілом:
  ```json
  { "error": "connect_token_expired" }
  ```
- **AND** сервер занулює застарілий `connect_token` (щоб не залишався в БД як сміття)
- **AND** бот сповіщає користувача в Telegram: "❌ Посилання застаріло, запитайте нове"

#### Scenario: Webhook з відсутніми обов'язковими полями
- **WHEN** Telegram-бот надсилає webhook з тілом без `chat_id` або без `connect_token`
- **THEN** сервер відповідає `HTTP 400` з описом помилки

#### Scenario: Повторний webhook з тим же токеном (idempotency)
- **GIVEN** webhook уже успішно відпрацював для `connect_token = "abc-123"` (токен занулено в БД)
- **WHEN** Telegram-бот з якоїсь причини надсилає той самий webhook повторно
- **THEN** сервер не знаходить токен → відповідає `HTTP 422 { "error": "connect_token_invalid" }`
- **AND** це безпечно: вже підключений `chat_id` користувача не змінюється

#### Scenario: Webhook коли `chat_id` уже зв'язаний з іншим користувачем
- **GIVEN** `chat_id = "123456789"` вже зв'язаний з `Bob`
- **AND** `Alice` має валідний `connect_token = "alice-token"`
- **WHEN** Telegram-бот надсилає webhook `{chat_id: "123456789", connect_token: "alice-token"}`
- **THEN** сервер:
  1. Знаходить `telegram_account` `Bob` за `chat_id` і **відв'язує** його (`Bob.telegram_account.chat_id = null`)
  2. Зв'язує `chat_id` з `Alice`
- **AND** відповідає `HTTP 200`
- **AND** `Bob` отримає сповіщення наступного разу що його Telegram відв'язано (опційно)

> **Обґрунтування:** один Telegram-акаунт може бути активно зв'язаний тільки з одним юзером системи. Якщо людина продала / поділилась Telegram-акаунтом — це її справа, але система не дозволяє двом юзерам отримувати нотифікації в один чат.

---

### Requirement: Доставка нотифікацій тільки підключеним користувачам
Worker, який матчить вільні слоти з watch'ами, МАЄ надсилати Telegram-нотифікації **тільки** користувачам із валідним `chat_id` (тобто `telegram_account.chat_id != null`).

#### Scenario: Worker пропускає користувача без підключеного Telegram
- **GIVEN** `Alice` має активний watch, який матчиться з вільним слотом
- **AND** `Alice` НЕ має підключеного Telegram (`chat_id = null`)
- **WHEN** worker обробляє новий слот
- **THEN** worker **не** намагається надіслати повідомлення (бо не знає куди)
- **AND** запис у `equeue_notification` все одно створюється (для дедуплікації), але з прапором `delivered: false`

#### Scenario: Worker надсилає нотифікацію підключеному користувачу
- **GIVEN** `Alice` має `chat_id = "123456789"` і активний watch, що матчиться з новим слотом
- **WHEN** worker обробляє слот
- **THEN** worker викликає Telegram Bot API `sendMessage` з `chat_id = "123456789"` і текстом нотифікації
- **AND** при успіху створює `equeue_notification` з `delivered: true`
