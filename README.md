# Housekeeping

Housekeeping is a standalone PHP CLI application for running safe, scheduled maintenance tasks and autonomous coding-agent housekeeping jobs against a target repository.

Do not add this package as a direct dependency of the project you want to maintain. Install Housekeeping in its own directory, point it at a repository, and let it act like a cautious junior developer for low-risk maintenance work.

It uses Symfony Console for commands, Symfony Lock to prevent overlapping runs, JSON state files for cooldown and quota tracking, and provider adapters so AI-assisted tasks can stay bounded and opt-in.

## Features

- Run housekeeping tasks from cron or systemd timers.
- Prevent concurrent runs with a filesystem lock.
- Track task state, learned repository metadata, provider usage, cooldowns, and runtime budgets in the Housekeeping workspace.
- Learn from recent commits before later provider-backed tasks run.
- Discover repository docs and TODO files automatically so later runs can sync docs with code.
- Execute safe default tasks with a local null provider.
- Keep provider-backed Codex, Gemini, and Copilot integrations disabled unless explicitly configured.
- Support per-project config files and configurable external coding-agent CLI flags.
- Validate the project with PHPStan and PHPUnit.

## Requirements

- PHP 8.2 or newer
- Composer

## Installation

```bash
composer install
```

Housekeeping is meant to be installed from its own checkout, not added to another project's `composer.json`.

## Quick start

1. Clone Housekeeping into its own directory and install dependencies there.
2. Clone or point to the repository you want Housekeeping to maintain.
3. Update [`config/tasks.php`](config/tasks.php) so `paths.repository_root` and task `working_directory` values point at that target repository, while `logs`, `state`, and `lock` stay inside the Housekeeping directory.
4. Run `php bin/agent-cron housekeeping:run --dry-run` first.
5. Only enable external providers after you are happy with the dry-run behavior and prompts.

See [QUICKSTART.md](QUICKSTART.md) for a full example.

Dogfooding note: with the default `max_tasks_per_run` of `3`, a fresh run on this repository currently executes `project:discover`, `commits:learn`, and `docs:refresh` first. Later due tasks run on subsequent invocations unless you raise that cap.

## Usage

List configured tasks:

```bash
php bin/agent-cron housekeeping:list
php bin/agent-cron --config=/path/to/project-a.php housekeeping:list
```

Inspect provider budgets, cooldowns, and free-resource probes:

```bash
php bin/agent-cron housekeeping:providers
php bin/agent-cron housekeeping:providers --json
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

Run one task:

```bash
php bin/agent-cron housekeeping:run --task=docs:refresh
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

## Configuration

Tasks, provider budgets, cooldowns, command timeouts, provider resource-probe commands, task priority, learned metadata paths, provider CLI arguments, and runtime paths are configured in [`config/tasks.php`](config/tasks.php) or in additional project-specific config files selected with `--config=/absolute/path/to/tasks.php` or `HOUSEKEEPING_CONFIG=/absolute/path/to/tasks.php`.

The default configuration uses `local-null-provider`, so fresh installs can run safely without external AI tools or credentials. External providers are disabled by default and must be enabled intentionally.

For real use, treat `config/tasks.php` as the control plane for one target repository:

- Keep the Housekeeping checkout, logs, state, and lock files in the standalone Housekeeping directory.
- Point `paths.repository_root` at the repository you want to maintain.
- Point task `working_directory` values at that same target repository when the task shells out to `git`, Composer, PHPStan, or other project-local tools.
- Set `input_files` and `context_files` to the actual docs and key files you want the doc-sync task to compare. In this repository's default dogfood config, `docs:refresh` tracks `README.md` and `QUICKSTART.md`, while `todo:refine` owns `TODO.md`.

The safest operating model is one Housekeeping workspace per maintained project so state, logs, prompts, and provider budgets stay isolated. If you share one workspace across multiple cron jobs, give each job its own config file with separate state, log, and lock paths.

The `housekeeping:providers` command compares external coding agents deterministically by sorting ready providers by parsed free capacity, next reset, remaining internal budget, and provider name. The default config wires optional local probe commands for Codex (`codex-cli-usage json`), Gemini (`gemini-cli-usage json`), and Copilot (`copilot-api check-usage --json`), but you can replace those commands with any compatible local tool that prints JSON or percentage-based text.

External providers can be tuned from config instead of code changes:

```php
'codex' => [
    'enabled' => true,
    'command' => ['codex', 'exec'],
    'arguments' => ['--yolo', '--sandbox', 'project-only'],
    'append_yolo' => false,
],
```

When `working_directory` is omitted for a provider, Housekeeping defaults that provider to `paths.repository_root` so coding agents execute inside the maintained project by default. The default config now relies on that behavior, so enabling Codex, Gemini, or Copilot against a copied config will run them inside the maintained repository unless you override it.

Default runs now start with `project:discover` and `commits:learn`, then continue with documentation, TODO, audit, and analysis tasks using the discovered metadata.

When you enable a real provider, keep the task scope conservative: review dependency updates, suggest or add missing tests, fix PHPDocs without runtime changes, refresh `AGENTS.md` or skills files from recent repository learnings, and sync docs with the current code, database, or infrastructure reality. Treat Housekeeping as a no-breaking-changes assistant, not an autonomous refactoring bot.

## Development

Run static analysis:

```bash
composer analyse
```

Run tests:

```bash
composer test
```

Optional AI-slop and mutation checks are installed through Composer scripts:

```bash
composer install-slop-scan
composer install-infection
```

## License

Housekeeping is released under the MIT License. See [`LICENSE`](LICENSE).
