# ⚙️ Configuration Guide

Housekeeping uses config files as the control plane for one maintained repository.

## 🧩 Main configuration areas

| Area | What it controls | Where to start |
| --- | --- | --- |
| `paths` | logs, state, lock files, repository root | point `repository_root` at the maintained project |
| `tasks` | intervals, scope, providers, task-specific commands | copy `config/project-template.php` and edit the top-level task blocks |
| `providers` | budgets, cooldowns, models, CLI commands | keep external providers disabled until dry runs look good |

## 📁 Path rules

| Path | Recommendation |
| --- | --- |
| Housekeeping checkout | keep it separate from the maintained repository when possible |
| `paths.logs`, `paths.state`, `paths.lock` | keep them inside the Housekeeping workspace |
| `paths.repository_root` | point it at the project Housekeeping should maintain |
| task `working_directory` | point it at the maintained repository when the task shells out to project tools |

## 📝 Documentation-related task inputs

The dogfood config keeps docs under review by tracking these kinds of files:

| Field | What to put there |
| --- | --- |
| `input_files` | files a maintenance task may edit, such as `README.md`, `docs/*.md`, `AGENTS.md`, or `TODO.md` |
| `context_files` | supporting files that explain the current reality, such as `composer.json`, task config, or agent guidance |

`housekeeping:doctor` validates enabled task file references so stale paths fail fast.

## 🤖 Provider behavior

| Provider | CLI shape added by Housekeeping | Notes |
| --- | --- | --- |
| Codex | `exec` | supports `model` config |
| Gemini | `--prompt` | supports provider ranking and preferred providers |
| Copilot | `--prompt` | supports provider ranking and preferred providers |
| Claude Code | `--print` | keep dangerous permission bypass opt-in only |
| OpenCode | `run` | bundled free-tier model is `opencode/minimax-m2.5-free` |

When a task uses `'provider' => 'auto'`, Housekeeping picks the first ready provider from the global readiness ranking unless that task also has `preferred_providers`.

## 🔥 Provider warmup pings

Rolling usage windows (e.g. Anthropic's ~5h window) reset from whenever they were first touched, not on a fixed clock. Running `housekeeping:providers` on its own short cron schedule lets Housekeeping nudge that reset earlier:

| Field | What it does |
| --- | --- |
| `warmup_command` | a real, cheap CLI invocation (e.g. a one-token prompt) fired when the provider is enabled and due; empty/unset disables it |
| `warmup_interval_seconds` | how often to fire, per provider; defaults to `18000` (5h) when omitted |

Warmup pings are exempt from `daily_budget` and `cooldown_seconds` — they're a window-reset trick, not task usage — and only fire from `housekeeping:providers` (never from the automatic `housekeeping:run` routing probe). `config/tasks.php` ships real, dogfooded `warmup_command` values per provider (`codex`, `gemini`, `copilot`, `claude`, `agy`); `opencode` is left empty since its CLI wasn't available to verify. A provider still needs `enabled => true` for its warmup to ever fire, so filled-in commands for currently-disabled providers (e.g. `gemini`, `agy`) stay dormant. See the comment above `'providers' => [` in `config/tasks.php` for what actually worked in testing versus what needs extra setup (account tier, login) first.

## 🎯 Practical defaults

| Goal | Recommendation |
| --- | --- |
| Safe first runs | keep `local-null-provider` enabled and external providers disabled |
| Easy onboarding | start from `config/project-template.php` |
| One project per workspace | isolate logs, prompts, cooldowns, and state |
| Faster experiments | run `commits:learn` first before enabling the full queue |

## 🧪 Helpful commands

```bash
php bin/agent-cron housekeeping:providers
php bin/agent-cron housekeeping:doctor
php bin/agent-cron housekeeping:list
php bin/agent-cron housekeeping:next
php bin/agent-cron housekeeping:state
```
