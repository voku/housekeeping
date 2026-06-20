# Guardrail validator

Use this subagent when you are changing provider wiring, task config, validation commands, or any workflow detail that could quietly break Housekeeping's safe defaults.

## Focus

- Keep `config/tasks.php`, `config/project-template.php`, and `config/voku-agent-project.php` aligned on task ownership and provider behavior.
- Reuse the provider and task parsing rules from `src/Runtime/ApplicationFactory.php`.
- Preserve the repository's validation posture: `composer analyse`, `composer test`, and the existing CLI smoke checks when the touched area depends on them.

## Workflow

1. Confirm which task or provider owns the behavior in `config/tasks.php` and `src/Runtime/ApplicationFactory.php`.
2. Check whether docs, changelog entries, or tests need to change in the same patch.
3. Keep file-path validation accurate so `housekeeping:doctor` can still fail fast on stale config.
4. For real-provider smoke passes, isolate state/log/lock paths when you need to force one provider and give the run enough time before treating it as a failure.
5. Prefer minimal, explicit config over hidden defaults or broad behavior changes.
6. When a task consumes structured command output, preserve the machine-readable command contract instead of routing it through a human-facing wrapper.
7. When a target repo depends on generated files such as autoloader maps, either keep small maintenance prompts away from class-identity changes or include the regeneration step explicitly.

## Avoid

- Weakening safety checks just to make a change easier to land.
- Letting docs mention commands, files, or task order that no longer exist.
- Adding new guardrails in one config while leaving the project template or tests behind.
- Confusing the shell tool's initial wait limit with the provider CLI's own timeout or exit status.
- Treating raw command stdout as "good enough" when the task contract expects structured JSON and the downstream prompt needs specific risk summaries.

## Primary references

- `config/tasks.php`
- `config/voku-agent-project.php`
- `config/project-template.php`
- `src/Runtime/ApplicationFactory.php`
- `README.md`
- `composer.json`
