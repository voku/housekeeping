# Quick start

Housekeeping should live in its own directory and operate on a target repository from there. Do not add it to the target project's Composer dependencies.

## 1. Create a standalone Housekeeping workspace

```bash
mkdir -p ~/work/housekeeping
cd ~/work/housekeeping

git clone https://github.com/voku/housekeeping.git housekeeping-tool
git clone git@github.com:your-org/your-project.git target-project

cd housekeeping-tool
composer install
```

At this point you should have two sibling directories:

- `~/work/housekeeping/housekeeping-tool`
- `~/work/housekeeping/target-project`

## 2. Point Housekeeping at the target project

Edit `/absolute/path/to/housekeeping-tool/config/tasks.php` and keep the Housekeeping runtime paths inside the Housekeeping checkout while moving repository-facing paths to the target project.

Start by introducing separate roots near the top of the file:

```php
<?php

declare(strict_types=1);

$housekeepingRoot = dirname(__DIR__);
$targetProjectRoot = '/absolute/path/to/target-project';
```

Then update the existing config values to follow this pattern:

```php
'paths' => [
    'logs' => $housekeepingRoot . '/var/logs',
    'state' => $housekeepingRoot . '/var/state/state.json',
    'lock' => $housekeepingRoot . '/var/lock',
    'repository_root' => $targetProjectRoot,
],
```

```php
'commits:learn' => [
    'working_directory' => $targetProjectRoot,
],
'blindspots:analyze' => [
    'context_files' => [
        $targetProjectRoot . '/README.md',
        $targetProjectRoot . '/AGENTS.md',
    ],
],
'docs:refresh' => [
    'input_files' => [
        $targetProjectRoot . '/README.md',
        $targetProjectRoot . '/docs/architecture.md',
    ],
    'context_files' => [
        $targetProjectRoot . '/composer.json',
        $targetProjectRoot . '/AGENTS.md',
    ],
],
'todo:refine' => [
    'input_files' => [$targetProjectRoot . '/TODO.md'],
],
'deps:audit' => [
    'working_directory' => $targetProjectRoot,
],
'phpstan:suggest-fixes' => [
    'working_directory' => $targetProjectRoot,
],
'slop:scan' => [
    'working_directory' => $targetProjectRoot,
],
```

Keep one Housekeeping workspace per maintained project if you want isolated state, logs, budgets, and prompts.
Provider-backed coding agents inherit `paths.repository_root` by default, so you only need to set a provider `working_directory` when you intentionally want them somewhere else.

## 3. Start with dry runs

From the Housekeeping checkout:

```bash
php bin/agent-cron housekeeping:list
php bin/agent-cron housekeeping:providers
php bin/agent-cron housekeeping:run --dry-run
```

This lets you confirm the task list, provider status, and file discovery before any provider-backed work runs.

If you want the first real run to process more than the default top four due tasks, raise `max_tasks_per_run` in the config before scheduling it.

## 4. Keep the maintenance scope conservative

Housekeeping works best when it behaves like a careful junior developer:

- review dependency updates
- suggest or add missing tests
- fix PHPDocs without changing runtime behavior
- refresh `AGENTS.md` or skills files using recent git learnings
- sync docs with the current code, database, and infrastructure reality

Keep provider-backed tasks focused on no-breaking-changes maintenance work.
The default blind-spot loop works best when its context files point at the docs or agent instructions you expect Housekeeping to keep aligned over time.

## 5. Schedule it

Example cron entry:

```cron
7 * * * * cd /absolute/path/to/housekeeping-tool && /usr/bin/php bin/agent-cron --config=/absolute/path/to/housekeeping-tool/config/project-a.php housekeeping:run >> var/logs/project-a-cron.log 2>&1
```
