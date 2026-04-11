# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**programel** — a spec-driven development workspace using the OpenSpec workflow system. No application source code,
language, or framework has been chosen yet. The repository currently contains only OpenSpec configuration and Claude
Code custom commands/skills.

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

## IDE

IntelliJ IDEA project (general module type, no specific SDK configured).
