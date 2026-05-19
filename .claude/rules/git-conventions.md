# Git Conventions

## ⛔ Strictly forbidden without explicit permission

**NEVER** run `git commit`, `git push`, `gh pr create`, or `gh pr merge` without an explicit instruction from the user.

- Finished writing code → stop → report what was done → wait for a command
- A plan that includes git steps is NOT permission to execute them
- Approving a plan is NOT permission to commit
- An open PR is NOT permission to push new commits
- Subagents must also be told not to commit or push
- The only permission: an explicit command such as "commit", "push", "create PR", "merge"

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
