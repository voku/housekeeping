# Housekeeping

Housekeeping is a standalone PHP CLI application for running safe, scheduled maintenance tasks and autonomous coding-agent housekeeping jobs against a target repository.

Do not add this package as a direct dependency of the project you want to maintain. Install Housekeeping in its own directory, point it at a repository, and let it act like a cautious junior developer for low-risk maintenance work.

It uses Symfony Console for commands, Symfony Lock to prevent overlapping runs, JSON state files for cooldown and quota tracking, and provider adapters so AI-assisted tasks can stay bounded and opt-in.

## Features

- Run housekeeping tasks from cron or systemd timers.
- Prevent concurrent runs with a filesystem lock.
- Track task state, learned repository metadata, provider usage, cooldowns, and runtime budgets in the Housekeeping workspace.
- Learn from recent commits before later provider-backed tasks run.
- Discover repository docs, agent guidance, and TODO files automatically so later runs can sync docs with code.
- Execute safe default tasks with a local null provider.
- Keep provider-backed Codex, Gemini, Copilot, Claude Code, and OpenCode integrations disabled unless explicitly configured.
- Support per-project config files and configurable external coding-agent CLI flags.
- Normalize provider responses into structured summaries and patch metadata for task state persistence.
- Support task-level preferred-provider routing when a task should override the global provider readiness ranking.
- Review recent real runs and `housekeeping.log`, then attempt one bounded self-improvement with automatic rollback if validation fails.
- Validate the project with PHPStan and PHPUnit.

## Requirements

- PHP 8.3 or newer
- Composer

## Installation

```bash
git clone https://github.com/voku/housekeeping.git housekeeping
cd housekeeping
composer install
cp config/project-template.php config/project-a.php
```

Housekeeping is meant to be installed from its own checkout, not added to another project's `composer.json`.

## Quick start

1. Clone or point to the repository you want Housekeeping to maintain.
2. Copy [`config/project-template.php`](config/project-template.php) to `config/project-a.php`.
3. Edit only the target-project paths in `config/project-a.php`, starting with `$targetProjectRoot`. Adjust the documentation, skill, context, and TODO file lists to match that destination repository.
4. Export `HOUSEKEEPING_CONFIG=/absolute/path/to/housekeeping/config/project-a.php` so local commands and coding agents automatically use the target-project config.
5. Run `php bin/agent-cron housekeeping:doctor`, `php bin/agent-cron housekeeping:list`, and `php bin/agent-cron housekeeping:run --dry-run`.
6. Only enable external providers after you are happy with the dry-run behavior and prompts.
7. Keep cron-driven agents in patch mode: they should never run `git commit` or create commits on their own.

The template intentionally starts with the generic discovery, learning, docs, skill-sync, and TODO tasks. Add stack-specific audit or fixer tasks later only when they match the destination project.
`housekeeping:doctor` now also validates that enabled tasks do not point at missing configured `input_files` or `context_files`, so stale dogfood/project paths fail fast.

See [QUICKSTART.md](QUICKSTART.md) for a full example.

Dogfooding note: with the default `max_tasks_per_run` of `4`, a fresh run on this repository currently executes `project:discover`, `commits:learn`, `blindspots:analyze`, and `docs:refresh` first. `skills:sync` becomes the next provider-backed guidance task when you raise the task budget to `5`; `todo:refine` follows after that once the earlier tasks are no longer due.

## Usage

List configured tasks:

```bash
php bin/agent-cron housekeeping:list
php bin/agent-cron housekeeping:list --json
php bin/agent-cron --config=/path/to/project-a.php housekeeping:list
```

Inspect provider budgets, cooldowns, and free-resource probes:

```bash
php bin/agent-cron housekeeping:providers
php bin/agent-cron housekeeping:providers --json
```

Inspect which task is due next:

```bash
php bin/agent-cron housekeeping:next
php bin/agent-cron housekeeping:next --json
```

Validate config, writable paths, and enabled provider wiring:

```bash
php bin/agent-cron housekeeping:doctor
php bin/agent-cron housekeeping:doctor --json
```

Run due tasks without executing providers:

```bash
php bin/agent-cron housekeeping:run --dry-run
php bin/agent-cron --config=/path/to/project-a.php housekeeping:run --dry-run
```

Run due tasks:

```bash
php bin/agent-cron housekeeping:run
php bin/agent-cron --config=/path/to/project-b.php housekeeping:run
```

Run one low-risk first real task:

```bash
php bin/agent-cron housekeeping:run --task=commits:learn
php bin/agent-cron --config=/path/to/project-a.php housekeeping:run --task=commits:learn
```

Run one task:

```bash
php bin/agent-cron housekeeping:run --task=docs:refresh
```

Dry-run the bounded self-improvement loop:

```bash
php bin/agent-cron housekeeping:run --dry-run --task=self-improve:housekeeping
```

Inspect persisted state:

```bash
php bin/agent-cron housekeeping:state
```

## Cron

An example crontab entry is available in [`crontab.example`](crontab.example):

```cron
7 * * * * cd /path/to/housekeeping && /usr/bin/php bin/agent-cron --config=/path/to/housekeeping/config/project-a.php housekeeping:run >> var/logs/project-a-cron.log 2>&1
37 * * * * cd /path/to/housekeeping && /usr/bin/php bin/agent-cron --config=/path/to/housekeeping/config/project-b.php housekeeping:run >> var/logs/project-b-cron.log 2>&1
```

Add `--verbose` when you want task-level progress in the cron log, including which task is currently running and whether it finished, skipped, or failed. Console lines emitted by `housekeeping:run` are timestamped so raw cron logs stay readable.

### WSL2

Housekeeping is usable from WSL2, but cron is usually not started automatically for you.

- If `systemctl is-system-running` reports `offline`, manage cron with `service` instead of `systemctl`.
- A practical setup inside the distro is `sudo service cron start`, then `crontab -e` to install the schedule.
- For unattended runs after Windows reboot or logon, either enable systemd in WSL or use Windows Task Scheduler to start the distro and cron.
- If cron launches Housekeeping under `root` while the maintained repository belongs to another Unix user, Housekeeping now re-runs `housekeeping:run` as the repository owner automatically so Git and user-scoped provider auth use the correct account.
- If the repository itself has the wrong ownership, fix that once outside Housekeeping (for example `sudo chown -R <user>:<group> /path/to/repository`) and then keep running cron as that same user.

### Linux / WSL2 setup example

Run these in the shell first:

```bash
sudo service cron start
crontab -e
```

Inside `crontab -e`, paste **only** the raw cron entry. Do **not** paste `sudo service cron start`, `crontab -e`, or the Markdown code fences.

```cron
7 * * * * cd /absolute/path/to/housekeeping && /usr/bin/php bin/agent-cron --config=/absolute/path/to/housekeeping/config/project-a.php housekeeping:run >> /absolute/path/to/housekeeping/var/logs/project-a/cron.log 2>&1
```

## Configuration

Tasks, provider budgets, cooldowns, command timeouts, provider resource-probe commands, task priority, learned metadata paths, preferred provider routing, provider CLI arguments, and runtime paths are configured in [`config/tasks.php`](config/tasks.php) or in additional project-specific config files selected with `--config=/absolute/path/to/tasks.php` or `HOUSEKEEPING_CONFIG=/absolute/path/to/tasks.php`. For a destination project, start by copying [`config/project-template.php`](config/project-template.php) so you only need to edit the target-repository paths instead of rewriting the dogfood config.

The default configuration uses `local-null-provider`, so fresh installs can run safely without external AI tools or credentials. External providers are disabled by default and must be enabled intentionally.

For real use, treat `config/tasks.php` as the control plane for one target repository:

- Keep the Housekeeping checkout, logs, state, and lock files in the standalone Housekeeping directory.
- Point `paths.repository_root` at the repository you want to maintain.
- Point task `working_directory` values at that same target repository when the task shells out to `git`, Composer, PHPStan, or other project-local tools.
- Set `input_files` and `context_files` to the actual docs and key files you want the doc-sync task to compare. In this repository's default dogfood config, `docs:refresh` tracks `README.md`, `QUICKSTART.md`, and `AGENTS.md`, `skills:sync` owns the `skills/*/SKILL.md` files, and `todo:refine` owns `TODO.md`.

The safest operating model is one Housekeeping workspace per maintained project so state, logs, prompts, and provider budgets stay isolated. If you share one workspace across multiple cron jobs, give each job its own config file with separate state, log, and lock paths.

The `housekeeping:providers` command compares external coding agents deterministically by sorting ready providers by parsed free capacity, next reset, remaining internal budget, and provider name. The default config wires optional local probe commands for Codex (`codex-cli-usage json`), Gemini (`gemini-cli-usage json`), Copilot (`copilot-api check-usage --json`), and Claude Code (`claude --version`). OpenCode ships without a default quota probe because its free-tier model selection is configured directly in the provider config, but you can still add any compatible local `resource_command` later.

If a task uses `'provider' => 'auto'`, Housekeeping picks the first ready provider from that global readiness ranking unless the task also declares `preferred_providers`, in which case the first ready preferred provider wins.

External providers can be tuned from config instead of code changes:

```php
'codex' => [
    'enabled' => true,
    'command' => ['codex'],
    'model' => 'gpt-5.5',
    'arguments' => ['--sandbox', 'workspace-write'],
],
'docs:refresh' => [
    'provider' => 'auto',
    'preferred_providers' => ['opencode', 'claude', 'codex'],
],
```

The built-in adapters add the provider-specific non-interactive CLI shape for you: Codex uses `exec`, Gemini uses `--prompt`, Copilot uses `--prompt`, Claude Code uses `--print`, and OpenCode uses `run`. When you set a provider `model`, Housekeeping maps that to the provider's `--model` flag for you, so switching Codex between values like `gpt-5.4` and `gpt-5.5` no longer requires hand-editing raw arguments. Keep `command` focused on the executable (or wrapper script), use `model` for the primary model selection, and reserve `arguments` for extra provider flags.

When `working_directory` is omitted for a provider, Housekeeping defaults that provider to `paths.repository_root` so coding agents execute inside the maintained project by default. The default config now relies on that behavior, so enabling Codex, Gemini, Copilot, Claude Code, or OpenCode against a copied config will run them inside the maintained repository unless you override it.

To try OpenCode quickly with its current free OpenCode Zen models, install it and enable the bundled provider entry:

```bash
npm install -g --no-audit --no-fund --no-progress opencode-ai@1.15.3
```

```php
'opencode' => [
    'enabled' => true,
    'command' => ['opencode'],
    'model' => 'opencode/minimax-m2.5-free',
],
'docs:refresh' => [
    'provider' => 'opencode',
],
```

If the Housekeeping checkout lives inside the maintained repository instead of alongside it, configure `tasks['project:discover']['ignored_paths']` so repository discovery skips that nested workspace (for example `['housekeeping']`). Otherwise the learned documentation and TODO metadata will drift toward the Housekeeping tool's own files.

Default runs now start with `project:discover` and `commits:learn`, then use `blindspots:analyze` to review the previous run before later provider-backed tasks continue with documentation, skill, TODO, audit, and analysis work.

`blindspots:analyze` is the self-optimization loop: it reviews the last completed housekeeping run, stores blind-spot guidance under `metadata.blind_spots`, and later provider-backed tasks receive that metadata alongside the normal repository-learning metadata.

Provider-backed tasks now also persist normalized provider output under task metadata so follow-up automation can reuse structured summaries, patch targets, and related provider metadata without reparsing raw stdout/stderr every time.

When you enable a real provider, keep the task scope conservative: review dependency updates, suggest or add missing tests, fix PHPDocs without runtime changes, refresh `AGENTS.md` or `SKILL.md` files from recent repository learnings, and sync docs with the current code, database, or infrastructure reality. The starter template now includes a dedicated `skills:sync` task that auto-discovers `SKILL.md` files and skips cleanly when a repository has none. Treat Housekeeping as a no-breaking-changes assistant, not an autonomous refactoring bot. Cron-driven agents should stop at patch suggestions or uncommitted file edits and must never run `git commit` or create commits by themselves.

## Development

Run static analysis:

```bash
composer analyse
```

Run tests:

```bash
composer test
```

Optional AI-slop, mutation, and provider CLI smoke checks are available through Composer scripts:

```bash
composer install-slop-scan
composer install-infection
composer provider-smoke
```

`composer provider-smoke` expects `codex`, `gemini`, `copilot`, `claude`, and `opencode` on `PATH`; CI and the Copilot setup steps install pinned CLI versions before running it.

## License

Housekeeping is released under the MIT License. See [`LICENSE`](LICENSE).
