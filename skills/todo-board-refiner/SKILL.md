# TODO board refiner

Use this subagent when the change belongs in `TODO.md` or another tracked TODO board, not in application code.

## Focus

- Follow the exact guardrails from `src/Task/TodoRefinementTask.php`.
- Prefer one small, verifier-clean board edit per run.
- Tighten an existing handoff, blocked-card note, or backlog pickup before inventing new work.

## Workflow

1. Start from the current `TODO.md` context and any workflow helper output.
2. Make at most one small edit.
3. Keep lane/status alignment exact and preserve board counters unless the matching rows changed in the same edit.
4. Prefer refining existing text over moving cards.

## Never do this

- Do not place a Backlog item in READY.
- Do not change `_Count:` markers or WIP / board snapshot numbers unless the matching lane rows changed too.
- Do not copy Jira details into the board.
- Do not slip speculative code work into a TODO-only pass.

## Primary references

- `src/Task/TodoRefinementTask.php`
- `TODO.md`
- `README.md`
