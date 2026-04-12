# programel

Personal platform at [programel.com](https://programel.com).

## Tech stack

- **API**: Symfony 7.2 (API-only, API Platform) — `api/`
- **Frontend**: Next.js (App Router, TypeScript) — `frontend/`
- **Database**: PostgreSQL 16, Redis 7
- **Infrastructure**: Docker, Nginx reverse proxy — `docker/`
- **CI/CD**: GitHub Actions, DigitalOcean
- **Auth**: JWT (httpOnly cookies) + refresh tokens

## Quick start

```bash
# 1. Generate local SSL certs
make certs

# 2. Add hosts
echo "127.0.0.1  programel.local test.programel.local lebenslauf.programel.local olcha.programel.local" | sudo tee -a /etc/hosts

# 3. Start dev environment
make dev
```

Dev environment runs at `https://programel.local`.

## Commands

| Command         | Description                                |
|-----------------|--------------------------------------------|
| `make dev`      | Start dev environment                      |
| `make stop`     | Stop all containers                        |
| `make test`     | Run all tests (PHPUnit + frontend)         |
| `make behat`    | Run Behat integration tests                |
| `make lint`     | Run linters (php-cs-fixer, phpstan, eslint, tsc) |
| `make lint-fix` | Auto-fix lint issues                       |
| `make migrate`  | Run database migrations                    |
| `make fixtures` | Load database fixtures                     |
| `make staging`  | Start staging environment                  |
| `make deploy`   | Deploy to production                       |
| `make backup`   | Run database backup                        |

## Project structure

```
api/                  Symfony API application
frontend/             Next.js frontend application
docker/               Dockerfiles and Nginx config
static/               Static HTML landings (lebenslauf, olcha)
openspec/             Specs and change management
  specs/              Canonical specifications
  changes/            Active and archived changes
  config.yaml         OpenSpec configuration
Makefile              Development and deployment commands
docker-compose.*.yml  Docker Compose per environment
```

## Domains

| Domain                        | Environment |
|-------------------------------|-------------|
| `programel.com`               | Production  |
| `test.programel.com`          | Staging     |
| `lebenslauf.programel.com`    | Static      |
| `olcha.programel.com`         | Static      |
| `programel.local`             | Local dev   |
