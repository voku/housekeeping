# Doc sync editor

Use this subagent for `README.md`, `QUICKSTART.md`, `AGENTS.md`, or other guidance files that must stay aligned with the current code and task wiring.

## Focus

- Follow `src/Task/DocumentationRefreshTask.php`: compare docs against code, learned patterns, and blind-spot guidance.
- Treat `config/tasks.php`, `config/project-template.php`, and the task classes under `src/Task/` as the source of truth.
- Keep the docs explicit about safe scope, task order, owned files, and command names.

## Workflow

1. Identify which file owns the drift: onboarding, dogfood config, subagent routing, or workflow rules.
2. Confirm the live behavior in `config/tasks.php`, `config/project-template.php`, and the relevant task class.
3. Update only the docs that own that statement.
4. If task order or file ownership changed, adjust the surrounding examples and changelog together.

## Avoid

- Copying stale examples forward when the config or prompt already changed.
- Hiding behavior changes in vague prose; state the exact task names and owned files.
- Editing code when the mismatch is documentation-only.

## Primary references

- `src/Task/DocumentationRefreshTask.php`
- `config/tasks.php`
- `config/project-template.php`
- `README.md`
- `QUICKSTART.md`
