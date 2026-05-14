# E-Queue Change Detection Design

**Date:** 2026-05-14  
**Status:** Approved

## Problem

The current `HtmlEqueueParser` uses CSS selectors (`[data-service-code]`, `[data-slot-at]`) that do not exist on the real page `https://munich.pasport.org.ua/solutions/e-queue`. Slot elements are rendered by JavaScript and are invisible to a plain HTTP GET. The parser always returns 0 slots — it is broken by design.

Additionally, the page blocks automated requests at the CDN level (returns 403 to most crawlers), so headless rendering is not a viable short-term fix.

## Strategy

Replace the broken slot-parsing approach with a simpler, more robust signal:

> When the "all slots taken" alert **disappears** from the page HTML → slots are likely available.

This gives us two immediate wins:
1. Telegram notification the moment the page state changes.
2. Raw HTML stored in DB — so when we finally do see a "slots available" page, we have the actual markup to study and build a real parser.

## Data Layer

### New table: `equeue_raw_html`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `fetched_at` | datetime | |
| `alert_present` | bool | `true` = "all slots taken" alert found in HTML |
| `html_body` | longtext | Full raw HTML response |

**Rotation**: On every poll, delete records where `fetched_at < NOW() - 8 hours`, then insert new record. At a 5-minute poll interval the table holds at most ~96 rows.

`alert_present` is stored on the record so future queries don't need to re-scan the HTML.

## Detection

Alert detection is a plain string check — no DOM parsing needed:

```php
$alertPresent = str_contains($html, 'Наразі всі місця зайняті');
```

## Notifications

### Who receives

All users where `TelegramAccount.chatId IS NOT NULL`. No filtering by watch or service — since we don't know which service has slots, everyone with a connected Telegram account is notified.

### Frequency

Every poll where the condition is true — no dedup.

### Notification matrix

| Poll result | Message sent |
|-------------|-------------|
| HTTP ok + `alert_present = false` | `⚡️ Вейкап Нео, стан змінився!\nhttps://munich.pasport.org.ua/solutions/e-queue` |
| HTTP error or network error | `🚨 Щось бляха, пішло не в ту дірку` |
| HTTP ok + `alert_present = true` | *(silence — normal state)* |

## Architecture

### New classes

**`EqueueRawHtml`** (entity)
- Fields: `id`, `fetchedAt`, `alertPresent`, `htmlBody`

**`EqueueRawHtmlRepository`**
- `save(EqueueRawHtml): void`
- `deleteOlderThan(DateTimeImmutable): void`

**`BroadcastTelegramMessage(string $text)`** (Messenger message)
- Single message class for all broadcast notifications

**`BroadcastTelegramHandler`**
- Queries all `TelegramAccount` where `chatId IS NOT NULL`
- Dispatches one `SendTelegramMessage` per user onto the `async` transport

### Modified: `PollEqueueHandler`

New flow:

```
1. Acquire lock (equeue.poll)
2. Fetch HTML via HttpEqueueFetcher
3. If HTTP error:
   a. Save EqueueSnapshot (status=http_error)
   b. Dispatch BroadcastTelegramMessage("🚨 Щось бляха...")
   c. Return
4. Detect: alertPresent = str_contains($html, 'Наразі всі місця зайняті')
5. Save EqueueRawHtml, delete records older than 8 hours
6. Save EqueueSnapshot (status=ok, slotCount=0)
7. If NOT alertPresent:
   a. Dispatch BroadcastTelegramMessage("⚡️ Вейкап Нео...")
```

### Removed

- `HtmlEqueueParser.php` — broken, based on fictional selectors
- `EqueueParseException.php` — only thrown by the parser
- Dispatch of `EvaluateWatchMessage` from `PollEqueueHandler`
- Test fixture `sample-page.html` + parser unit tests

### Kept dormant (for future)

- `EqueueWatch` entity + API — watch subscriptions still stored, useful once real parser exists
- `EvaluateWatchHandler`, `EvaluateWatchMessage`, `SlotSignature` — preserved for future slot matching

## What This Does NOT Do

- Does not parse individual slot times or service names (impossible without JS rendering).
- Does not match slots against user watch preferences (no slot data available).
- Does not deduplicate alert-absent notifications — every poll fires while condition holds.

## Future Path

Once `equeue_raw_html` captures a page snapshot where `alert_present = false`, the actual markup of available slots becomes available for study. At that point a real parser can be written and `EvaluateWatchHandler` re-enabled.
