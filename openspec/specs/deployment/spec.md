## ADDED Requirements

### Requirement: DigitalOcean server provisioning
The system SHALL provide documentation and scripts for initial server setup on DigitalOcean.

#### Scenario: Server initialization
- **WHEN** a fresh DigitalOcean droplet is provisioned
- **THEN** a setup script installs Docker Engine, Docker Compose plugin, configures firewall (UFW: 22, 80, 443), and creates a deploy user

### Requirement: Environment-based configuration
The system SHALL manage all secrets and configuration through `.env` files, never committed to git.

#### Scenario: Production secrets
- **WHEN** the application starts in production
- **THEN** it reads DATABASE_URL, APP_SECRET, REDIS_URL, and other secrets from `.env.prod` on the server

#### Scenario: Example env file
- **WHEN** a developer checks out the repository
- **THEN** `.env.example` files exist for both API and root Docker config with all required variables documented

### Requirement: Rollback capability
The system SHALL support rolling back to a previous Docker image version using a deploy history file.

#### Scenario: Deploy records current version
- **WHEN** a successful deployment completes
- **THEN** the current image tags are appended to `.deploy-history` on the server (format: `timestamp image_tag`)

#### Scenario: Rollback to previous version
- **WHEN** `make rollback` is executed
- **THEN** the system reads the second-to-last entry from `.deploy-history`, pulls those image tags, and restarts services

#### Scenario: Rollback with explicit tag
- **WHEN** `make rollback TAG=abc123` is executed
- **THEN** the system pulls images with the specified tag and restarts services

### Requirement: Database migrations on deploy
The system SHALL automatically run Doctrine migrations as part of each deployment.

#### Scenario: Migrations run after image pull
- **WHEN** a deployment pulls new images and restarts services
- **THEN** `bin/console doctrine:migrations:migrate --no-interaction` is executed in the API container before traffic is routed to it

#### Scenario: Migration failure stops deploy
- **WHEN** a migration fails during deployment
- **THEN** the deployment halts and the previous containers remain running

### Requirement: Docker cleanup on server
The system SHALL automatically clean up unused Docker resources to prevent disk exhaustion.

#### Scenario: Weekly Docker prune
- **WHEN** a cron job runs weekly on the server
- **THEN** `docker system prune -af --volumes --filter "until=168h"` removes dangling images, stopped containers, and unused volumes older than 7 days

#### Scenario: Cleanup preserves running resources
- **WHEN** Docker prune runs
- **THEN** currently running containers, their images, and their volumes are NOT removed

### Requirement: Database backup
The system SHALL provide automated database backup mechanism.

#### Scenario: Scheduled backup
- **WHEN** a cron job runs daily at 3:00 AM server time
- **THEN** a compressed PostgreSQL dump (`pg_dump | gzip`) is created at `/backups/programel_YYYY-MM-DD.sql.gz`
- **AND** backups older than 7 days are automatically deleted
