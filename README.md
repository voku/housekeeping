# Housekeeping

Housekeeping is a standalone PHP CLI tool for safe scheduled maintenance and agentic housekeeping runs against a target repository. 🤖

> [!IMPORTANT]
> Install Housekeeping in its own directory, point it at another repository, and keep cron-driven runs in patch mode. It should behave like a careful junior developer, and you should still review every resulting change.

## ✨ Highlights

| Feature | Why it helps |
| --- | --- |
| ⏰ Scheduled maintenance | run low-risk cleanup from cron or systemd timers |
| 🔒 Locking | avoid overlapping runs with a filesystem lock |
| 🧠 Learned context | reuse recent commits, docs, TODOs, and run metadata |
| 🧰 Safe defaults | start with `local-null-provider` before enabling real agents |
| 🎯 Provider routing | prefer specific providers or let Housekeeping auto-pick one |
| 🔁 Bounded self-improvement | let Housekeeping review itself with rollback-aware validation |

## 📋 Requirements

| Requirement | Version / note |
| --- | --- |
| PHP | 8.3 or newer |
| Composer | required |

## 🚀 Installation

```bash
git clone https://github.com/voku/housekeeping.git housekeeping
cd housekeeping
composer install
cp config/project-template.php config/project-a.php
```

Housekeeping is meant to live in its own checkout, not inside another project's `composer.json`.

## ⚡ Quick start

1. Copy [`config/project-template.php`](config/project-template.php) to `config/project-a.php`.
2. Point `$targetProjectRoot` at the repository you want to maintain.
3. Export `HOUSEKEEPING_CONFIG=/absolute/path/to/housekeeping/config/project-a.php`.
4. Run the safe first checks:

```bash
php bin/agent-cron housekeeping:doctor
php bin/agent-cron housekeeping:list
php bin/agent-cron housekeeping:run --dry-run
```

The starter config begins with `project:discover`, `commits:learn`, `blindspots:analyze`, `docs:refresh`, `skills:sync`, and `todo:refine`.

## 🗂️ Documentation

| Doc | Purpose |
| --- | --- |
| [`QUICKSTART.md`](QUICKSTART.md) | full setup walkthrough |
| [`docs/README.md`](docs/README.md) | docs hub |
| [`docs/scheduling.md`](docs/scheduling.md) | cron setup, task changes, unattended maintenance ideas |
| [`docs/configuration.md`](docs/configuration.md) | config structure, provider setup, and safe defaults |
| [`AGENTS.md`](AGENTS.md) | repository-specific agent guidance |

## 💡 Why people use it

| Situation | Example |
| --- | --- |
| 🌙 Overnight | refine TODOs and refresh docs |
| 🧭 Between normal work | keep skills and guidance aligned |
| 🧪 Over a weekend | run longer low-risk audit or test-improvement waves |

That is the real agentic value: Housekeeping can keep chipping away at repetitive maintenance work while you sleep. 😅 You still review the patch before commit or merge.

## 🛠️ Development

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

`composer provider-smoke` expects `codex`, `gemini`, `copilot`, `claude`, and `opencode` on `PATH`; CI and the Copilot setup steps install pinned CLI versions before running it. When `agy` is installed, the same script also checks its version and non-interactive permission-bypass flags.

## 📄 License

Housekeeping is released under the MIT License. See [`LICENSE`](LICENSE).
