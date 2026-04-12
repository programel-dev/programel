# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**programel** — personal platform (programel.com) built with a spec-driven workflow.

### Tech stack

- **API**: Symfony 7.2 (API-only) — `api/` — entities, controllers, API Platform, Behat, PHPUnit
- **Frontend**: Next.js (App Router, TypeScript, Sentry) — `frontend/`
- **Database**: PostgreSQL 16, Redis 7
- **Infrastructure**: Docker (dev/prod/staging), Nginx reverse proxy — `docker/`
- **Static sites**: `static/lebenslauf/`, `static/olcha/` — plain HTML landings served by Nginx
- **CI/CD**: GitHub Actions, DigitalOcean deployment
- **Auth**: JWT (lexik) with httpOnly cookies, refresh tokens (gesdinet)

### Development commands (Makefile)

| Command         | Purpose                                    |
|-----------------|--------------------------------------------|
| `make dev`      | Start dev environment (docker compose)     |
| `make stop`     | Stop all containers                        |
| `make test`     | Run all tests (PHPUnit + frontend)         |
| `make behat`    | Run Behat integration tests                |
| `make lint`     | Run linters (php-cs-fixer, phpstan, eslint, tsc) |
| `make lint-fix` | Auto-fix lint issues                       |
| `make migrate`  | Run database migrations                    |
| `make staging`  | Start staging environment                  |
| `make deploy`   | Deploy to production                       |

### Domains

- `programel.com` — main (production)
- `test.programel.com` — staging
- `lebenslauf.programel.com` — static resume
- `olcha.programel.com` — static landing
- `programel.local` — local development (with mkcert SSL)

## OpenSpec Workflow

The project uses the `spec-driven` schema (configured in `openspec/config.yaml`). All development follows an
artifact-driven cycle managed by the `openspec` CLI.

### Core commands (via `/opsx:*`)

| Command                | Purpose                                                                            |
|------------------------|------------------------------------------------------------------------------------|
| `/opsx:explore`        | Think through problems, investigate code — no implementation allowed               |
| `/opsx:propose <name>` | Create a change and generate all artifacts (proposal → design → tasks) in one step |
| `/opsx:new <name>`     | Start a new change step-by-step, pausing after each artifact                       |
| `/opsx:apply <name>`   | Implement tasks from a change, checking off items in tasks.md                      |
| `/opsx:verify <name>`  | Verify implementation matches artifacts (completeness, correctness, coherence)     |
| `/opsx:sync <name>`    | Sync delta specs from a change into main specs (`openspec/specs/`)                 |
| `/opsx:archive <name>` | Move completed change to `openspec/changes/archive/YYYY-MM-DD-<name>/`             |
| `/opsx:onboard`        | Guided walkthrough of the full OpenSpec cycle                                      |

### Change lifecycle

1. **Explore** — investigate and clarify before committing to a direction
2. **Propose/New** — create `openspec/changes/<name>/` with artifacts: `proposal.md`, `design.md`,
   `specs/<capability>/spec.md`, `tasks.md`
3. **Apply** — implement tasks, mark checkboxes `- [x]` as completed
4. **Verify** — validate implementation against specs and design
5. **Sync** — merge delta specs into main specs at `openspec/specs/`
6. **Archive** — preserve decision history in archive directory

### Key directories

- `openspec/changes/` — active changes with their artifacts
- `openspec/changes/archive/` — completed and archived changes
- `openspec/specs/` — main (canonical) specs, updated via sync
- `openspec/config.yaml` — schema and project context configuration

### Specs format

Delta specs use structured sections: `## ADDED Requirements`, `## MODIFIED Requirements`, `## REMOVED Requirements`,
`## RENAMED Requirements`. Requirements use WHEN/THEN/AND scenario format for testability.

### Conventions

- Ukrainian/English docs, kebab-case for file names, PSR-12 for PHP
- Frontend: see `frontend/AGENTS.md` — always check Next.js docs in `node_modules/next/dist/docs/` before writing code

## IDE

IntelliJ IDEA project.
