## ADDED Requirements

### Requirement: Multi-stage Docker builds
The system SHALL use multi-stage Dockerfiles for both API and frontend services to produce minimal production images.

#### Scenario: API production image build
- **WHEN** `docker build --target prod -f docker/api/Dockerfile .` is executed
- **THEN** the resulting image contains only PHP-FPM runtime, compiled extensions, and application code without dev dependencies, Composer cache, or build tools

#### Scenario: Frontend production image build
- **WHEN** `docker build --target prod -f docker/frontend/Dockerfile .` is executed
- **THEN** the resulting image contains Node.js runtime and the Next.js standalone build (`.next/standalone/`) without dev dependencies, source maps, or node_modules

### Requirement: Development environment with zero local dependencies
The system SHALL provide a Docker Compose dev configuration that requires only Docker Engine on the host machine.

#### Scenario: First-time developer setup
- **WHEN** a developer clones the repository and runs `make dev` (or `docker compose -f docker-compose.dev.yml up`)
- **THEN** all services start (PHP-FPM, Nginx, PostgreSQL, Redis, Node dev server) and the application is accessible at `localhost`

#### Scenario: PHP hot-reload in development
- **WHEN** a developer modifies a PHP file in `api/`
- **THEN** the change is immediately reflected without restarting containers (via bind mount)

#### Scenario: Next.js hot-reload in development
- **WHEN** a developer modifies a React component in `frontend/`
- **THEN** the browser updates via Next.js Fast Refresh without manual refresh

#### Scenario: Xdebug available in development
- **WHEN** `XDEBUG_MODE=debug` environment variable is set
- **THEN** the PHP-FPM container enables step debugging on port 9003

### Requirement: Production Docker Compose configuration
The system SHALL provide a separate production Docker Compose configuration optimized for DigitalOcean deployment.

#### Scenario: Production startup
- **WHEN** `docker compose -f docker-compose.prod.yml up -d` is executed on the server
- **THEN** all services start with production-optimized settings: OPcache enabled, no Xdebug, built assets served via Nginx, SSL terminated

#### Scenario: Container restart policy
- **WHEN** any container crashes in production
- **THEN** Docker restarts it automatically (restart: unless-stopped)

### Requirement: Async worker container
The system SHALL run a dedicated worker container for processing Symfony Messenger async jobs via Redis.

#### Scenario: Worker runs in production
- **WHEN** `docker-compose.prod.yml` is started
- **THEN** a `worker` container runs `bin/console messenger:consume async` with restart policy

#### Scenario: Worker runs in development
- **WHEN** `docker-compose.dev.yml` is started
- **THEN** a `worker` container runs with the same command, sharing the API bind mount for hot-reload

#### Scenario: Worker processes jobs
- **WHEN** a message is dispatched to the `async` transport
- **THEN** the worker container picks it up and processes it within seconds

#### Scenario: Worker retries failed messages
- **WHEN** a message processing fails
- **THEN** the worker retries up to 3 times with exponential backoff (1s, 4s, 16s)

#### Scenario: Worker sends failed messages to dead letter queue
- **WHEN** a message fails all 3 retry attempts
- **THEN** the message is moved to the `failed` transport (dead letter queue) for manual inspection

#### Scenario: Worker memory limit
- **WHEN** the worker process exceeds 128MB memory usage
- **THEN** the worker gracefully stops and Docker restarts it (via `--memory-limit=128M` flag)

### Requirement: Email capture in development via Mailpit
The system SHALL capture all outgoing emails in development and provide a web UI for viewing them.

#### Scenario: Mailpit catches emails
- **WHEN** Symfony sends an email in dev environment
- **THEN** the email is captured by Mailpit instead of being sent to real recipients

#### Scenario: Mailpit web UI accessible
- **WHEN** the dev environment is running
- **THEN** Mailpit web UI is accessible at `http://localhost:8025` showing all captured emails

### Requirement: Persistent data volumes
The system SHALL use named Docker volumes for database and Redis data in production.

#### Scenario: Database persistence across restarts
- **WHEN** the PostgreSQL container is stopped and started again
- **THEN** all data persists via the named volume `pg_data`

#### Scenario: Development data isolation
- **WHEN** running in dev mode
- **THEN** database data is stored in a separate dev volume, isolated from production data
