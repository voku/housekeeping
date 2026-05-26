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
