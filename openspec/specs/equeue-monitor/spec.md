# equeue-monitor

Multi-tenant monitoring service that polls the Munich consulate e-queue page
(`https://munich.pasport.org.ua/solutions/e-queue`), evaluates user
subscriptions against the resulting slot snapshots, and delivers Telegram
notifications when matching slots become available.

## Requirements

### Requirement: Periodic e-queue polling

The system SHALL periodically fetch the public e-queue page at
`EQUEUE_TARGET_URL` and persist a normalized snapshot of services and
available slots.

#### Scenario: Scheduled poll every interval
- **WHEN** the scheduler interval (`EQUEUE_POLL_INTERVAL`, default 300s)
  elapses
- **THEN** a `PollEqueueMessage` is dispatched onto the `async` Messenger
  transport and consumed by the worker

#### Scenario: Successful poll persists snapshot
- **WHEN** the e-queue page returns HTTP 2xx and the parser succeeds
- **THEN** an `EqueueSnapshot` row is created with `httpStatus`,
  normalized `payload` (jsonb), `slotCount`, `parserVersion`,
  and `fetchedAt`

#### Scenario: Failed fetch is recorded, not crashed
- **WHEN** the e-queue page returns 4xx/5xx or network error
- **THEN** a snapshot is still persisted with the non-success
  `httpStatus`, status `http_error`, and empty slot list; evaluation is
  skipped; the handler completes without throwing

#### Scenario: Parse error is recorded
- **WHEN** the parser raises an exception on a valid HTTP response
- **THEN** a snapshot is persisted with status `parse_error`,
  `parserVersion`, and the error message in payload; evaluation is
  skipped

#### Scenario: Concurrent polls are serialized
- **WHEN** a poll handler is already running (Symfony Lock
  `equeue.poll` is held)
- **THEN** the duplicate handler invocation returns early without
  fetching

### Requirement: Raw HTML capture and alert-based change detection

The system SHALL save the full HTML response on every successful poll into a
rolling 8-hour buffer, detect page-state changes via a sentinel string, and
immediately broadcast a Telegram notification to all connected users when the
"all slots taken" alert disappears or an HTTP error occurs.

> **Context:** The target page (`https://munich.pasport.org.ua/solutions/e-queue`)
> renders available slots via client-side JavaScript, making them invisible to a
> plain HTTP GET. The only reliably detectable server-side signal is the presence
> or absence of the "all slots taken" alert in the static HTML body.

#### Scenario: Target page and HTTP configuration
- **WHEN** the fetcher runs
- **THEN** it performs a `GET` against `EQUEUE_TARGET_URL`
  (default `https://munich.pasport.org.ua/solutions/e-queue`) using the
  Symfony scoped HTTP client `equeue.client` with `timeout: 10s`,
  `max_duration: 15s`, header `User-Agent` from `EQUEUE_USER_AGENT`,
  `Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8`,
  and `Accept-Language: uk-UA,uk;q=0.9,en;q=0.8`; no cookies, no auth,
  no query parameters

#### Scenario: Raw HTML is saved on every successful poll
- **WHEN** the e-queue page returns HTTP 2xx
- **THEN** the full response body is persisted as an `EqueueRawHtml` row
  with `fetchedAt`, `alertPresent` (bool), and `htmlBody` (full text);
  before inserting, all rows with `fetchedAt < NOW() - 8 hours` are deleted,
  keeping the table as a rolling 8-hour buffer (≈96 rows at 5-minute intervals)

#### Scenario: Alert detection
- **WHEN** the fetcher returns an HTTP 2xx response
- **THEN** `alertPresent` is set to `true` if the body contains the literal
  string `Наразі всі місця зайняті`, and `false` otherwise;
  detection is a plain `str_contains` check — no DOM parsing

#### Scenario: Alert absent triggers broadcast
- **WHEN** `alertPresent` is `false`
- **THEN** a `BroadcastTelegramMessage` is dispatched every poll until the
  alert reappears (no dedup); the message text is
  `"⚡️ Вейкап Нео, стан змінився!\nhttps://munich.pasport.org.ua/solutions/e-queue"`

#### Scenario: HTTP error triggers broadcast
- **WHEN** the e-queue page returns 4xx/5xx or a network error
- **THEN** an `EqueueSnapshot` is persisted with `status = http_error`;
  no `EqueueRawHtml` is saved; a `BroadcastTelegramMessage` is dispatched
  with text `"🚨 Щось бляха, пішло не в ту дірку"` on every failing poll

#### Scenario: Alert present — silence
- **WHEN** `alertPresent` is `true`
- **THEN** no `BroadcastTelegramMessage` is dispatched; only `EqueueRawHtml`
  and `EqueueSnapshot` are persisted

#### Scenario: Broadcast fan-out
- **WHEN** a `BroadcastTelegramMessage` is dispatched
- **THEN** `BroadcastTelegramHandler` queries all `TelegramAccount` rows where
  `chatId IS NOT NULL` and dispatches one `SendTelegramMessage(chatId, text)`
  per account onto the `async` transport; users without a connected Telegram
  account receive nothing

#### Scenario: Snapshot records detection version
- **WHEN** any `EqueueSnapshot` is persisted by `PollEqueueHandler`
- **THEN** `parserVersion` is set to `'alert-detection-v1'` to distinguish
  these rows from any future parser-based snapshots

### Requirement: User watch subscriptions

The system SHALL allow authenticated users to create, read, update, and
delete watch subscriptions specifying which service and date range to
monitor.

#### Scenario: Authenticated user creates a watch
- **WHEN** an authenticated user POSTs to `/api/v1/equeue_watches` with
  `serviceCode`, `dateFrom`, `dateTo`, `active`
- **THEN** the watch is persisted with `user` set to the authenticated
  user automatically (clients cannot set `user` directly)

#### Scenario: Watch is scoped to its owner
- **WHEN** an authenticated user GETs `/api/v1/equeue_watches`
- **THEN** only watches owned by that user are returned; watches of
  other users are not visible

#### Scenario: User cannot modify another user's watch
- **WHEN** an authenticated user issues PATCH or DELETE against
  `/api/v1/equeue_watches/{id}` belonging to a different user
- **THEN** the API returns HTTP 403 or 404 (per API Platform security
  expression)

#### Scenario: Unauthenticated access denied
- **WHEN** an unauthenticated request hits `/api/v1/equeue_watches`
- **THEN** the API returns HTTP 401

### Requirement: Slot matching against watches

The system SHALL evaluate each newly persisted snapshot against all
active user watches and identify slots matching the watch's service and
date range.

#### Scenario: New matching slot triggers evaluation
- **WHEN** `PollEqueueHandler` finishes persisting an OK snapshot with
  ≥1 slot and there is ≥1 active watch in DB
- **THEN** an `EvaluateWatchMessage` is dispatched per active watch

#### Scenario: Slot matches service and date range
- **WHEN** a snapshot contains a slot whose `serviceCode` equals the
  watch `serviceCode` AND `slotAt` date is within
  `[watch.dateFrom, watch.dateTo]`
- **THEN** the evaluator considers the slot a candidate for notification

### Requirement: Notification deduplication

The system SHALL send at most one Telegram notification per
(watch, slot) pair, even across multiple polls or concurrent workers.

#### Scenario: First match dispatches notification
- **WHEN** a watch matches a slot for the first time
- **THEN** an `EqueueNotification` row is inserted with
  `slotSignature = sha256(serviceCode|isoDateTime)` and a
  `SendTelegramMessage` is dispatched

#### Scenario: Duplicate slot suppressed
- **WHEN** the same (watch, slot) pair is evaluated in a subsequent
  poll
- **THEN** the evaluator checks existence via
  `EqueueNotificationRepository::exists()` before inserting; if the
  notification already exists the slot is skipped and no message is
  dispatched

#### Scenario: Race-safe under concurrent handlers
- **WHEN** two evaluator handlers process the same (watch, slot) pair
  simultaneously
- **THEN** exactly one insertion succeeds and exactly one notification
  is dispatched

### Requirement: Telegram delivery

The system SHALL deliver notifications to users via the Telegram Bot
API using the bot configured by `TELEGRAM_BOT_TOKEN`.

#### Scenario: Delivery on the async transport
- **WHEN** an `EvaluateWatchMessage` produces a `SendTelegramMessage`
- **THEN** the message is dispatched onto the `async` transport and
  consumed by the worker, which calls `sendMessage` against
  `api.telegram.org/bot<token>/`

#### Scenario: Telegram 429 retried with backoff
- **WHEN** Telegram returns HTTP 429 or 5xx
- **THEN** the handler throws a retryable exception and Messenger
  retries up to `max_retries: 3` with exponential backoff per the
  default retry strategy

#### Scenario: Chat blocked unbinds account
- **WHEN** Telegram returns HTTP 403 (bot blocked by user) for a chat
- **THEN** the corresponding `TelegramAccount.chatId` is set to NULL
  and the message handler raises an `UnrecoverableMessageHandlingException`
  (no retries)

#### Scenario: User without bound Telegram is skipped
- **WHEN** a watch belongs to a user whose `TelegramAccount` is missing
  or has NULL `chatId`
- **THEN** no `SendTelegramMessage` is dispatched (the evaluator
  silently skips)

### Requirement: Telegram account linking

The system SHALL allow users to link their Telegram chat to their
account via a one-time deep-link.

#### Scenario: User requests connect link
- **WHEN** an authenticated user POSTs `/api/v1/telegram/connect-link`
- **THEN** a URL-safe random token is generated, stored on the
  user's `TelegramAccount`, and a response
  `{ url: "https://t.me/<bot>?start=<token>", expiresAt }` is returned

#### Scenario: Token expires after 15 minutes
- **WHEN** more than 900 seconds elapse from token issuance
- **THEN** the token cannot be used to bind a chat

#### Scenario: Token is single-use
- **WHEN** a `/start <token>` is processed successfully
- **THEN** the token and its expiry are cleared from `TelegramAccount`
  and a subsequent `/start <token>` with the same value has no effect

#### Scenario: Webhook accepts /start binding
- **WHEN** Telegram POSTs to
  `/api/v1/telegram/webhook/{TELEGRAM_WEBHOOK_SECRET}` an update with
  `message.text == "/start <token>"` for a valid unexpired token
- **THEN** the `chat.id` from the update is stored on the matching
  `TelegramAccount`, `connectedAt` is set, and a confirmation message
  is sent

#### Scenario: Webhook rejects invalid secret
- **WHEN** the URL secret does not equal `TELEGRAM_WEBHOOK_SECRET`
  (constant-time comparison via `hash_equals`)
- **THEN** the API returns HTTP 404 without revealing the reason

#### Scenario: User status check
- **WHEN** an authenticated user GETs `/api/v1/telegram/status`
- **THEN** the API returns `{ connected: bool, connectedAt: string|null }`
