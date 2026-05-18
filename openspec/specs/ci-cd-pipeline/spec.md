## ADDED Requirements

### Requirement: Multi-stage CI pipeline
The system SHALL run a GitHub Actions workflow with jobs: lint → test → build → deploy.

#### Scenario: Pipeline triggers on push
- **WHEN** code is pushed to any branch
- **THEN** the workflow runs lint and test jobs

#### Scenario: Deploy only on main branch
- **WHEN** code is pushed to the `main` branch and all previous jobs pass
- **THEN** the deploy job executes automatically

### Requirement: Lint job
The system SHALL run linters for both PHP and TypeScript/JavaScript code.

#### Scenario: PHP linting
- **WHEN** the lint job runs
- **THEN** PHP CS Fixer and PHPStan check API code quality

#### Scenario: Frontend linting
- **WHEN** the lint job runs
- **THEN** ESLint and TypeScript compiler check frontend code quality

### Requirement: Test job
The system SHALL run automated tests for API and frontend.

#### Scenario: API unit tests with database
- **WHEN** the test job runs for the API
- **THEN** PHPUnit tests execute against a PostgreSQL service container

#### Scenario: API integration tests with Behat
- **WHEN** the test job runs for the API
- **THEN** Behat feature files execute against the Symfony kernel with a PostgreSQL service container

#### Scenario: Frontend tests
- **WHEN** the test job runs for the frontend
- **THEN** Jest/Vitest executes all frontend unit and component tests

### Requirement: Build job with Docker image registry
The system SHALL build and push Docker images to GitHub Container Registry (ghcr.io).

#### Scenario: Image build and push
- **WHEN** the build job runs on the main branch
- **THEN** Docker images for API and frontend are built and pushed to ghcr.io with the commit SHA as tag

### Requirement: Automated deployment via SSH
The system SHALL deploy to DigitalOcean by pulling new images and restarting services via SSH.

#### Scenario: Deploy with migrations
- **WHEN** the deploy job runs
- **THEN** the workflow connects via SSH, pulls new images, runs database migrations, and restarts services via `docker compose -f docker-compose.prod.yml up -d`

#### Scenario: Migration failure aborts deploy
- **WHEN** database migrations fail during deployment
- **THEN** the deploy job fails, previous containers remain running, and the workflow reports the error

### Requirement: Telegram webhook re-registration on every deploy
The system SHALL re-register the Telegram bot webhook after every successful deployment to ensure Telegram uses the current server IP (bypasses Telegram-side DNS cache).

#### Scenario: Webhook registered after deploy
- **WHEN** the deploy job runs successfully
- **THEN** `app:telegram:set-webhook https://programel.com` is executed, refreshing the webhook URL in Telegram's registry
