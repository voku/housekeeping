# Housekeeping subagents

Housekeeping keeps its repo-specific subagents as provider-neutral `SKILL.md` files under `skills/`. Start here, pick the closest subagent, and keep every change inside the tool's safe-maintenance posture.

## Routing

| Subagent | Use when | Primary references |
| --- | --- | --- |
| `skills/run-archaeologist/SKILL.md` | You need to map the repository, recent commits, task ordering, or learned metadata before changing anything. | `config/tasks.php`, `src/Runtime/RepositoryInspector.php`, `src/Task/CommitLearningTask.php`, `README.md` |
| `skills/blindspot-curator/SKILL.md` | You need to review recent runs, prompt drift, missed patterns, or self-improvement guidance. | `src/Task/BlindSpotAnalysisTask.php`, `src/Task/SelfImprovementTask.php`, `README.md`, `TODO.md` |
| `skills/doc-sync-editor/SKILL.md` | You are updating `README.md`, `QUICKSTART.md`, or this file to match the real code and workflow. | `src/Task/DocumentationRefreshTask.php`, `config/tasks.php`, `config/project-template.php` |
| `skills/todo-board-refiner/SKILL.md` | You are touching `TODO.md` or any future board-style TODO workflow. | `src/Task/TodoRefinementTask.php`, `TODO.md`, `README.md` |
| `skills/guardrail-validator/SKILL.md` | You are changing config, provider wiring, validation, or rollout guardrails and need to keep the repo safe. | `config/tasks.php`, `src/Runtime/ApplicationFactory.php`, `README.md`, `composer.json` |

## Default working loop

1. Start with the smallest useful idea.
2. Blind-spot it against the current task prompts, docs, tests, and `TODO.md`.
3. Tighten the wording, file ownership, and validation story.
4. Repeat that loop a few times before you land the final edit.

## Repo guardrails

- Keep Housekeeping conservative: bounded edits, no broad rewrites, no autonomous commits, and no hidden fallback behavior.
- Prefer existing task names, config keys, and doc patterns over inventing new abstractions.
- When task ordering, task file lists, or agent guidance changes, update `README.md`, `QUICKSTART.md`, `CHANGELOG.md`, and the affected tests together.
- When a blind spot reveals a missed local pattern, update `AGENTS.md` or the matching `SKILL.md` so later runs get the corrected guidance.
- For TODO changes, obey the strict single-small-edit workflow from `TodoRefinementTask`; for code changes, validate with the repository's existing analysis and test commands.
- For tasks that parse command output (`deps:audit`, `slop:scan`, similar future tasks), keep the configured command machine-readable; do not swap in a human-oriented wrapper unless the task logic changes with it.
- For narrow target-repo maintenance prompts, either forbid class add/move/rename work outright or spell out the matching generated-artifact step (for example, regenerating autoloader maps) in the same prompt.
- For forced real-provider smoke runs, prefer isolated state/config files and generous wait windows; a tool wait expiring means "keep reading" unless the provider command itself actually failed.
