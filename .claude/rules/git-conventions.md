# Git Conventions

## Branch Naming

Structure: `<type>/<issue-number>-<short-description>`

Types: `feat/`, `fix/`, `hotfix/`, `chore/`, `docs/`, `test/`, `release/`

Examples:

- `feat/1234-add-user-authentication`
- `fix/5678-navbar-overflow`
- `hotfix/production-issue-login-failure`
- `release/v1.3.0`

## Commit Messages

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>)(#<task-number>): <short-description>
```

Both `scope` and `#<task-number>` are optional:

- `feat(auth): add OAuth2 support`
- `feat(#1234): add OAuth2 support`
- `feat: add OAuth2 support`

Types: `feat`, `fix`, `chore`, `docs`, `refactor`, `test`

Guidelines:

- Use imperative mood: "add", "fix", "update" — not "added", "fixed", "updated"
- Keep the title under 50 characters
- Never add `Co-Authored-By` trailers to commits
- Never mention AI, Claude, agents, or automation tools in commit messages or PR descriptions
