# CLAUDE.md

## Project

**programel.com** — personal platform (https://programel.com) built with a spec-driven workflow.

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

## Git workflow

**Never commit directly to `main`.** Always create a branch from a clean `main` before making any changes:

```
git checkout main && git pull
git checkout -b <type>/<description>
```

Commit all work there, then open a PR to merge into `main`.

## Development commands (Makefile)

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

## Dev environment known gotchas

### `API_INTERNAL_URL` — nginx, not PHP-FPM
Port 9000 is FastCGI, not HTTP. Next.js Server Actions do plain HTTP fetches — connecting to `http://api:9000` causes `ECONNRESET`. Always use `http://nginx` as `API_INTERNAL_URL`. The nginx port 80 server block must handle `/api/` via `fastcgi_pass` (without redirecting to HTTPS).

### nginx must forward `X-Forwarded-Host` with port
Next.js 16 validates that `x-forwarded-host` matches the `origin` header for Server Actions (CSRF protection). Use `$http_host` (includes port) instead of `$host` (strips port):
```nginx
proxy_set_header Host $http_host;
proxy_set_header X-Forwarded-Host $http_host;
```
Without this, POSTing a Server Action returns 500 "Invalid Server Actions request".

### `docker compose restart` does not pick up env var changes
Use `docker compose up -d --force-recreate <service>` after changing env vars in `docker-compose.yml`.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.
