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
cp config/project-template.php config/project-a.php
```

At this point you should have two sibling directories:

- `~/work/housekeeping/housekeeping-tool`
- `~/work/housekeeping/target-project`

## 2. Point Housekeeping at the target project

Edit `/absolute/path/to/housekeeping-tool/config/project-a.php`.
The template already keeps Housekeeping runtime paths inside the tool checkout, so you only need to point the repository-facing values at the target project.
Near the top of the copied template, update these variables in place:

```php
<?php

declare(strict_types=1);

$housekeepingRoot = dirname(__DIR__);
$targetProjectRoot = '/absolute/path/to/target-project';

$documentationFiles = [
    $targetProjectRoot . '/README.md',
];

$documentationContextFiles = [
    $targetProjectRoot . '/composer.json',
    $targetProjectRoot . '/AGENTS.md',
];

$todoFiles = [
    $targetProjectRoot . '/TODO.md',
];
```

If you keep the Housekeeping checkout nested inside the maintained repository instead of as a sibling, also exclude that nested tool directory from repository discovery:

```php
'project:discover' => [
    'ignored_paths' => ['housekeeping-tool'],
],
```

Keep one Housekeeping workspace per maintained project if you want isolated state, logs, budgets, and prompts.
Provider-backed coding agents inherit `paths.repository_root` by default, so you only need to set a provider `working_directory` when you intentionally want them somewhere else. Their adapters also add the provider-specific non-interactive CLI shape automatically (`codex exec`, `gemini --prompt`, `copilot --prompt`, `claude --print`, `opencode run`).
If you want Housekeeping to auto-pick a provider for one task but still bias that task toward a specific agent, set `'provider' => 'auto'` and add `preferred_providers` in priority order.
The project template keeps `self-improve:housekeeping` and the PHP-specific audit/fix tasks out of the copied config on purpose, so the destination project gets the generic maintenance automation first instead of spending early cron budget on Housekeeping itself or on stack-specific commands you may not use.

## 3. Start with dry runs

From the Housekeeping checkout:

```bash
export HOUSEKEEPING_CONFIG=/absolute/path/to/housekeeping-tool/config/project-a.php
php bin/agent-cron housekeeping:doctor
php bin/agent-cron housekeeping:list
php bin/agent-cron housekeeping:providers
php bin/agent-cron housekeeping:run --dry-run
```

This lets you confirm the task list, provider status, and file discovery before any provider-backed work runs.
For real runs that you want to tail in a terminal or cron log, add `--verbose` to emit timestamped per-task `[run]`, `[ok]`, `[skip]`, and `[fail]` progress lines.
When you enable a real provider, keep it in patch mode: cron-triggered Housekeeping runs should never run `git commit` or create commits on their own.

For OpenCode specifically, the bundled template already includes a ready-to-enable free-tier model selection:

```bash
npm install -g --no-audit --no-fund --no-progress opencode-ai@1.15.3
```

```php
'opencode' => [
    'enabled' => true,
    'arguments' => ['--model', 'opencode/minimax-m2.5-free'],
],
'docs:refresh' => [
    'provider' => 'opencode',
],
```

For a first real smoke test, prefer a single `commits:learn` run before scheduling the full queue. It only reads recent git history and updates Housekeeping state:

```bash
php bin/agent-cron housekeeping:run --task=commits:learn
```

If you want the first real run to process more than the default top four due tasks, raise `max_tasks_per_run` in the config before scheduling it. In this repository's dogfood config, the default top four are now `project:discover`, `commits:learn`, `blindspots:analyze`, and `docs:refresh`; set the limit to `5` if you also want `todo:refine` in that same cron wave.

This repository's dogfood config keeps `docs:refresh` pointed at `README.md` and `QUICKSTART.md`, while `todo:refine` owns `TODO.md`. Keep those file lists aligned whenever CI or provider setup changes, because `housekeeping:doctor` now fails fast when enabled tasks reference missing `input_files` or `context_files`.

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

In the current dogfood setup, repeated real runs are useful instead of purely advisory: `todo:refine` only counts as successful when it actually changed a tracked TODO document, while `docs:refresh` can keep refining `README.md` and `QUICKSTART.md` over successive runs once learned state exists.

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
