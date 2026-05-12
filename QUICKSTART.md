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

If you keep the Housekeeping checkout nested inside the maintained repository instead of as a sibling, also exclude that nested tool directory from repository discovery:

```php
'project:discover' => [
    'ignored_paths' => ['housekeeping-tool'],
],
```

Keep one Housekeeping workspace per maintained project if you want isolated state, logs, budgets, and prompts.
Provider-backed coding agents inherit `paths.repository_root` by default, so you only need to set a provider `working_directory` when you intentionally want them somewhere else. Their adapters also add the provider-specific non-interactive CLI shape automatically (`codex exec`, `gemini --prompt`, `copilot --prompt`, `claude --print`).
If you want Housekeeping to auto-pick a provider for one task but still bias that task toward a specific agent, set `'provider' => 'auto'` and add `preferred_providers` in priority order.

## 3. Start with dry runs

From the Housekeeping checkout:

```bash
php bin/agent-cron housekeeping:list
php bin/agent-cron housekeeping:providers
php bin/agent-cron housekeeping:run --dry-run
```

This lets you confirm the task list, provider status, and file discovery before any provider-backed work runs.
For real runs that you want to tail in a terminal or cron log, add `--verbose` to emit timestamped per-task `[run]`, `[ok]`, `[skip]`, and `[fail]` progress lines.
When you enable a real provider, keep it in patch mode: cron-triggered Housekeeping runs should never run `git commit` or create commits on their own.

For a first real smoke test, prefer a single `commits:learn` run before scheduling the full queue. It only reads recent git history and updates Housekeeping state:

```bash
php bin/agent-cron housekeeping:run --task=commits:learn
```

If you want the first real run to process more than the default top four due tasks, raise `max_tasks_per_run` in the config before scheduling it. On the IT-Portal dogfood config, the default top four are now `project:discover`, `commits:learn`, `blindspots:analyze`, and `todo:refine`; set the limit to `5` if you also want `docs:refresh` in that same cron wave.

The IT-Portal dogfood config can also run lower-frequency hygiene waves inspired by recent maintenance commits: `phpdocs:refresh`, `magic-numbers:reduce`, `todo-comments:cleanup`, and `small-refactors:polish`. These jobs select a small file set from git history or existing `TODO@` markers and only count as successful when at least one selected file actually changed.

Its `todo:refine` job is also aligned with the repository board workflow: it reads board-helper output from the local PHP TODO scripts (`todo_board_cli.php summary`, `next-pull`, and filtered `render` output), uses that compiled context when editing `TODO.md`, and validates the result with `verify_todo_board.php` before the run is allowed to finish successfully. In WSL2/Linux cron this avoids `docker compose exec` for these board-only parser steps and has proven much more reliable. If validation fails, Housekeeping restores the pre-run `TODO.md` content instead of leaving an invalid board behind.

The dogfood config also includes `self-improve:housekeeping` as a slower meta-maintenance wave. After about 10 non-dry runs since its last review window, it analyzes recent run summaries plus `housekeeping.log`, lets one provider attempt at most one small improvement inside the Housekeeping package (`src`, `config`, `tests`, `README.md`, `QUICKSTART.md`), then runs `php -l` on changed PHP files plus its configured validation/smoke commands. If any check fails, Housekeeping restores the previous files automatically.

You can inspect that self-improvement path safely before enabling it in cron:

```bash
php bin/agent-cron housekeeping:run --dry-run --task=self-improve:housekeeping
```

Keep a realistic timeout budget for that provider-backed TODO pass. In practice the run is usually much faster, but occasional provider-side capacity retries can stretch an otherwise valid refinement run, so the dogfood config deliberately leaves more headroom than the earlier minimal cron baseline.

## 4. Keep the maintenance scope conservative

Housekeeping works best when it behaves like a careful junior developer:

- review dependency updates
- suggest or add missing tests
- fix PHPDocs without changing runtime behavior
- refresh `AGENTS.md` or skills files using recent git learnings
- sync docs with the current code, database, and infrastructure reality

Keep provider-backed tasks focused on no-breaking-changes maintenance work.
They should return patches or uncommitted edits for review, never self-commit from cron.
The default blind-spot loop works best when its context files point at the docs or agent instructions you expect Housekeeping to keep aligned over time.

In the current IT-Portal dogfood setup, repeated real runs are now useful instead of purely advisory: `todo:refine` only counts as successful when it actually changed a tracked TODO document, and a three-pass isolated trial showed the queue can keep refining `TODO.md` plus `README.md` over successive runs once learned state exists.

## 5. Schedule it

Example cron entry:

```cron
7 * * * * cd /absolute/path/to/housekeeping-tool && /usr/bin/php bin/agent-cron --config=/absolute/path/to/housekeeping-tool/config/project-a.php housekeeping:run >> var/logs/project-a-cron.log 2>&1
```

### WSL2 note

Housekeeping works from WSL2, but cron is usually a manual runtime setup step inside the distro:

- if `systemctl is-system-running` reports `offline`, use `service` rather than `systemctl`
- start cron with `sudo service cron start`
- install the schedule with `crontab -e`
- if you need the schedule to survive Windows reboot or logon without manually opening WSL first, enable systemd in WSL or use Windows Task Scheduler to launch the distro and cron
- if a system/root cron entry starts Housekeeping under the wrong Unix account, Housekeeping now re-runs itself as the repository owner automatically before the task queue starts
- if the repository ownership itself is wrong, repair it once with `sudo chown -R <user>:<group> /path/to/repository` and prefer installing the schedule for that same user

Generic Linux / WSL2 example:

Run these in the shell first:

```bash
sudo service cron start
crontab -e
```

Inside `crontab -e`, paste **only** the raw cron entry. Do **not** paste `sudo service cron start`, `crontab -e`, or the Markdown code fences.

```cron
7 * * * * cd /absolute/path/to/housekeeping-tool && /usr/bin/php bin/agent-cron --config=/absolute/path/to/housekeeping-tool/config/project-a.php housekeeping:run >> /absolute/path/to/housekeeping-tool/var/logs/project-a/cron.log 2>&1
```
