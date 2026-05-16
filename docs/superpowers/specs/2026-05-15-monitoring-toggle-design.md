# Design: Worker Monitoring Toggle

**Date:** 2026-05-15  
**Status:** Approved

## Problem

The equeue worker polls the Munich consulate page every N minutes (default 5 min). There's currently no way to pause polling without stopping the worker container. Admins need a simple on/off control accessible from the web UI.

## Scope

Enable/disable equeue polling via a toggle on the main page, visible only to users with `ROLE_ADMIN`. "Disabled" means `PollEqueueHandler` exits early without fetching — the worker container keeps running.

Out of scope: monitoring dashboard, logs viewer, per-user toggle, notification muting.

---

## Architecture

```
┌──────────────┐     GET/PATCH      ┌──────────────────────────┐
│  Next.js     │ ◀────────────────▶ │  Symfony API             │
│  Main page   │  /api/v1/admin/    │  AdminMonitoringController│
│  (SSR)       │  monitoring        │                          │
└──────────────┘                    └────────────┬─────────────┘
                                                 │ read/write
                                                 ▼
                                    ┌──────────────────────────┐
                                    │  monitoring_config table  │
                                    │  (single row, id=1)       │
                                    └────────────┬─────────────┘
                                                 │ isEnabled()
                                                 ▼
                                    ┌──────────────────────────┐
                                    │  PollEqueueHandler        │
                                    │  (worker container)       │
                                    └──────────────────────────┘
```

---

## Data Model

**New entity: `MonitoringConfig`**

| Field        | Type      | Notes                              |
|--------------|-----------|------------------------------------|
| `id`         | int       | Always = 1, single row             |
| `enabled`    | bool      | Default `true`                     |
| `updatedAt`  | datetime  | Set on every toggle                |
| `updatedBy`  | User (FK) | Who last changed it                |

**Migration:** creates `monitoring_config` table and inserts the initial row `(1, true, now(), null)`.

---

## Backend

### Repository

`MonitoringConfigRepository::isEnabled(): bool` — reads `enabled` from the single row. Called once per poll cycle (every 5 min — negligible DB load).

### API Endpoints

**`GET /api/v1/admin/monitoring`**
- Auth: `ROLE_ADMIN`
- Response `200`:
  ```json
  {
    "enabled": true,
    "updatedAt": "2026-05-15T10:00:00+00:00",
    "updatedBy": "alice@example.com"
  }
  ```

**`PATCH /api/v1/admin/monitoring`**
- Auth: `ROLE_ADMIN`
- Body: `{ "enabled": false }`
- Sets `enabled`, `updatedAt = now()`, `updatedBy = current user`
- Response `200`: same shape as GET

Both endpoints return `403` for non-admin authenticated users and `401` for unauthenticated requests.

### PollEqueueHandler change

First line of `__invoke()`:

```php
if (!$this->monitoringConfigRepository->isEnabled()) {
    $this->logger->info('equeue polling disabled, skipping');
    return;
}
```

No other changes to the handler.

---

## Frontend

**Visibility rule:** the monitoring block renders only when `user.roles` includes `ROLE_ADMIN`. Non-admins see nothing — the block is not rendered, not hidden.

**SSR state load:** `GET /api/v1/admin/monitoring` is called in the Server Component at page render time. No loading state or flicker.

**Interaction:**

```
┌─────────────────────────────────┐
│  Моніторинг                     │
│  Polling equeue  [  ●  ON  ]    │
│  Оновлено: 15 хв тому · Alice   │
└─────────────────────────────────┘
```

- Toggle switch — standard HTML `<input type="checkbox">` styled as toggle
- Optimistic UI: toggle flips immediately on click
- Server Action calls `PATCH /api/v1/admin/monitoring`
- On error: toggle reverts + brief inline error message ("Не вдалося зберегти")
- `updatedAt` and `updatedBy` displayed under toggle, refreshed after successful save

---

## Security

- Both API endpoints are secured with `is_granted('ROLE_ADMIN')` via Symfony security voters
- `updatedBy` is set server-side from the JWT token — client cannot spoof it
- No rate limiting needed (low-frequency admin action)

---

## Testing

| Test | Type |
|------|------|
| `GET /api/v1/admin/monitoring` returns 200 for admin | Behat |
| `GET /api/v1/admin/monitoring` returns 403 for regular user | Behat |
| `PATCH /api/v1/admin/monitoring` toggles the flag | Behat |
| `PollEqueueHandler` exits early when `enabled = false` | PHPUnit |
| `PollEqueueHandler` runs normally when `enabled = true` | PHPUnit (existing) |
| Frontend toggle not rendered for non-admin | Jest/Playwright |
