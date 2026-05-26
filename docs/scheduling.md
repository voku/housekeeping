# ⏰ Scheduling & Task Guide

Housekeeping works best when cron stays boring and the task list stays expressive.

## 🧠 Core idea

Your cron job usually keeps running the same command:

```cron
7 * * * * cd /absolute/path/to/housekeeping-tool && /usr/bin/php bin/agent-cron --config=/absolute/path/to/housekeeping-tool/config/project-a.php housekeeping:run >> /absolute/path/to/housekeeping-tool/var/logs/project-a/cron.log 2>&1
```

The real behavior lives in the top-level `'tasks'` array inside `config/project-a.php`.

## 🔧 Common task changes

| Goal | What to change in `config/project-a.php` | Example |
| --- | --- | --- |
| Add a task | copy an existing task block or enable an optional one | enable `deps:audit` later |
| Pause a task | set `'enabled' => false` | stop `todo:refine` for a while |
| Remove a task | delete the task block | permanently drop a maintenance wave |
| Run more or less often | change `interval_seconds` | run docs once per day instead of once per hour |
| Limit scope | edit `input_files`, `context_files`, `working_directory`, or task-specific options | keep `docs:refresh` focused on selected docs |

## 🧪 Safe workflow after every task change

Run these from the Housekeeping checkout:

```bash
php bin/agent-cron housekeeping:doctor
php bin/agent-cron housekeeping:list
php bin/agent-cron housekeeping:run --dry-run
```

## 🗂️ Built-in task cheat sheet

| Task | Purpose | Typical use |
| --- | --- | --- |
| `project:discover` | learn repository docs, TODOs, and key files | keep repo metadata fresh |
| `commits:learn` | learn from recent git history | feed later provider-backed tasks |
| `blindspots:analyze` | review the previous run and store blind spots | improve later runs gradually |
| `docs:refresh` | keep tracked docs aligned with reality | refresh README, docs, and guidance files |
| `skills:sync` | keep `SKILL.md` files aligned | maintain repo-specific agent skills |
| `todo:refine` | refine tracked TODO files | clean up backlog items overnight |
| `deps:audit` | inspect dependency updates | review stale or risky packages |
| `phpstan:suggest-fixes` | inspect static-analysis output | suggest low-risk fixes |
| `self-improve:housekeeping` | let Housekeeping maintain itself in a bounded way | slower meta-maintenance wave |

## 🌙 Why unattended runs help

| Window | Good fit | Why it helps |
| --- | --- | --- |
| Overnight | `todo:refine`, `docs:refresh` | wake up to a cleaner backlog and fresher docs |
| During the day | `commits:learn`, `blindspots:analyze`, `skills:sync` | keep context and guidance current between real work |
| Weekend | test-focused, audit-style, or larger doc waves | use idle time for repetitive low-risk maintenance |

This is the agentic value: Housekeeping can keep chipping away at repetitive cleanup work without constant babysitting. 😎

## 🛡️ Guardrails

- keep tasks low-risk
- prefer docs, TODO refinement, audits, tests, and guidance upkeep
- keep cron-triggered runs in patch mode
- review every resulting change before commit or merge

## 🐧 WSL2 notes

| Situation | What to do |
| --- | --- |
| `systemctl is-system-running` says `offline` | use `service` instead of `systemctl` |
| cron is not running | start it with `sudo service cron start` |
| you need persistence after Windows reboot/logon | enable systemd in WSL or launch the distro via Windows Task Scheduler |
| cron starts under the wrong Unix user | let Housekeeping re-run as the repository owner or fix the schedule user |
