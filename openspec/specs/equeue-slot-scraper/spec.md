# equeue-slot-scraper

Headless Chromium microservice (`playwright-equeue`) that scrapes available
queue slots from `https://munich.pasport.org.ua/solutions/e-queue` by
intercepting AJAX responses, and exposes them via a simple HTTP API consumed
by the equeue-monitor poller.

## Requirements

### Requirement: Playwright scraper exposes HTTP API

The `playwright-equeue` service SHALL expose `GET /slots` endpoint that returns available queue slots as JSON. The service SHALL run on port 3001 inside the Docker network.

#### Scenario: Successful slot fetch
- **WHEN** `GET /slots` is called
- **THEN** service launches headless Chromium, navigates to `https://munich.pasport.org.ua/solutions/e-queue`, selects service=4, intercepts AJAX responses, and returns `{"success": true, "slots": [...], "fetchedAt": "..."}`

#### Scenario: No slots available
- **WHEN** the page indicates no available dates
- **THEN** service returns `{"success": true, "slots": [], "fetchedAt": "..."}`

#### Scenario: Page load timeout
- **WHEN** page fails to load or AJAX does not fire within 30 seconds
- **THEN** service returns `{"success": false, "reason": "timeout", "fetchedAt": "..."}`

#### Scenario: Cloudflare or network error
- **WHEN** the target page returns a block page or network error
- **THEN** service returns `{"success": false, "reason": "blocked", "fetchedAt": "..."}`

### Requirement: Slot data format

Each slot entry in the `slots` array SHALL contain `date` (ISO 8601 date string `YYYY-MM-DD`) and `times` (array of time strings `HH:MM`).

#### Scenario: Multiple dates with times
- **WHEN** the queue has slots on multiple dates
- **THEN** `slots` contains one entry per available date, each with a non-empty `times` array

#### Scenario: Date with no times
- **WHEN** a date has no available times
- **THEN** that date SHALL NOT appear in the `slots` array

### Requirement: Service health check

The service SHALL expose `GET /health` returning HTTP 200 with `{"status": "ok"}`.

#### Scenario: Health check
- **WHEN** `GET /health` is called
- **THEN** service returns HTTP 200 `{"status": "ok"}`
