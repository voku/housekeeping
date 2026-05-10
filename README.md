# Housekeeping

Housekeeping is a standalone PHP CLI application for running safe, scheduled maintenance tasks in open-source projects.

It uses Symfony Console for commands, Symfony Lock to prevent overlapping runs, JSON state files for cooldown and quota tracking, and provider adapters so AI-assisted tasks can stay bounded and opt-in.

## Features

- Run housekeeping tasks from cron or systemd timers.
- Prevent concurrent runs with a filesystem lock.
- Track task state, provider usage, cooldowns, and runtime budgets.
- Execute safe default tasks with a local null provider.
- Keep provider-backed Codex, Gemini, and Copilot integrations disabled unless explicitly configured.
- Validate the project with PHPStan and PHPUnit.

## Requirements

- PHP 8.2 or newer
- Composer

## Installation

```bash
composer install
```

## Usage

List configured tasks:

```bash
php bin/agent-cron housekeeping:list
```

Run due tasks without executing providers:

```bash
php bin/agent-cron housekeeping:run --dry-run
```

Run due tasks:

```bash
php bin/agent-cron housekeeping:run
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
7 * * * * cd /path/to/housekeeping && /usr/bin/php bin/agent-cron housekeeping:run >> var/logs/cron.log 2>&1
```

## Configuration

Tasks, provider budgets, cooldowns, command timeouts, and runtime paths are configured in [`config/tasks.php`](config/tasks.php).

The default configuration uses `local-null-provider`, so fresh installs can run safely without external AI tools or credentials. External providers are disabled by default and must be enabled intentionally.

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
