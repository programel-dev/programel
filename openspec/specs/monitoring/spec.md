## ADDED Requirements

### Requirement: Health check endpoints
The system SHALL expose health check endpoints for all critical services.

#### Scenario: API health check
- **WHEN** a GET request is sent to `/api/health`
- **THEN** the API returns HTTP 200 if the service is healthy, HTTP 503 if database or Redis is unreachable

#### Scenario: Docker health checks
- **WHEN** Docker inspects container health
- **THEN** each service container has a HEALTHCHECK instruction that verifies service readiness

### Requirement: Structured logging
The system SHALL output logs in structured JSON format in production.

#### Scenario: Symfony structured logs
- **WHEN** the API writes a log entry in production
- **THEN** the log is formatted as JSON with fields: timestamp, level, message, context, channel

#### Scenario: Nginx access logs
- **WHEN** Nginx handles a request in production
- **THEN** the access log entry is in JSON format with fields: timestamp, status, method, uri, response_time, client_ip

### Requirement: Error tracking via Sentry
The system SHALL report unhandled exceptions and errors to Sentry in production.

#### Scenario: Symfony exception reported
- **WHEN** an unhandled exception occurs in the Symfony API in production
- **THEN** the exception with full stack trace, request context, and user info is sent to Sentry

#### Scenario: Next.js error reported
- **WHEN** a server-side or client-side error occurs in the Next.js frontend in production
- **THEN** the error is reported to Sentry with component tree and browser info

#### Scenario: Sentry disabled in development
- **WHEN** the application runs in dev mode
- **THEN** errors are NOT sent to Sentry (controlled via `SENTRY_DSN` env var being empty)

### Requirement: Container resource monitoring
The system SHALL expose basic container metrics for monitoring.

#### Scenario: Docker stats accessible
- **WHEN** an operator runs `docker stats` or queries the Docker API
- **THEN** CPU, memory, network I/O metrics are available for each container

#### Scenario: Disk usage alerts
- **WHEN** disk usage on the server exceeds 85%
- **THEN** a warning is logged and optionally sent via webhook notification
