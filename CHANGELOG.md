# Changelog

All notable changes to this project will be documented in this file.

## 0.4.0 - 2026-06-20

- Added LearningsConsolidateTask with scheduling support, update configs and tests

## 0.3.0 - 2026-06-20

- Added first-class Antigravity CLI (`agy`) provider support, explicit opt-in `--dangerously-skip-permissions` configuration for both Claude Code and `agy`, and an auto-mode config for sibling `voku/agent-*` projects based on the IT-Portal provider setup.

## 0.2.0 - 2026-05-26

- Hardened config loading so commands now fail cleanly when `--config` or `HOUSEKEEPING_CONFIG` points at a missing or unreadable file instead of surfacing a raw PHP warning.
- Added a generic `skills:sync` selected-files task for repositories with `SKILL.md` files, and split the advanced example-project config so skill-file validation no longer piggybacks on `docs:refresh`.
- Added a dogfood `AGENTS.md`, five repo-specific `SKILL.md` subagents, and the config/discovery wiring so Housekeeping can keep its own agent guidance aligned with the code and TODO workflow.
- Added a first-class provider `model` config key so Codex, Gemini, Copilot, Claude Code, and OpenCode can select models such as `gpt-5.4` or `gpt-5.5` without spelling `--model` inside raw arguments.
- Tightened the repo's own agent guidance so forced provider smoke runs use isolated state plus longer wait windows, and so wait-window expiry is not misread as a real provider failure.
- Hardened the advanced example-project selected-file commands with pipe-safe fallbacks, switched its `slop:scan` task to a Dockerized `slop-scan.phar --json` invocation that matches Housekeeping's JSON contract, and tightened TODO refinement guidance around board snapshot and Agent Task Brief integrity.
- Tightened `deps:audit` so it now parses Composer's JSON output into structured abandoned/major-update summaries, and extended the example-project/TODO guardrails around Decision Log freshness plus autoloader regeneration for any unavoidable class-identity edits.

## 0.1.0 - 2026-05-18

- Added `housekeeping:doctor` to validate config, writable paths, and enabled provider wiring from the CLI.
- Added `housekeeping:next` to show the next due housekeeping work in table and JSON formats.
- Added persisted per-run `results` and `errors` history so completed runs expose richer execution details.
- Added `config/project-template.php` so local installs can copy a target-project config instead of rewriting the dogfood config from scratch.
- Added built-in OpenCode provider support, including default config entries, CLI smoke coverage, and docs for trying the bundled free-tier model selection.
- Unified task config parsing for `housekeeping:list` and `housekeeping:next` so interval and priority fallbacks stay consistent.
- Hardened command output and regression coverage for `housekeeping:doctor`, `housekeeping:list`, `housekeeping:next`, and `TaskRunner` so the diff-based Infection CI job stays green.
- Extended `housekeeping:doctor` so it now fails fast when enabled tasks reference missing `input_files` or `context_files`, and restored the dogfood `TODO.md` target so `todo:refine` has a real tracked document again.
- Simplified README and Quick Start onboarding so local installs can export `HOUSEKEEPING_CONFIG`, run the doctor/list/dry-run flow quickly, and point Housekeeping at a destination project with fewer edits.
- Tightened the dogfood `self-improve:housekeeping` validation pipeline so accepted changes must now pass PHPStan in addition to PHPUnit and the existing CLI smoke checks.
- Updated the provider CLI smoke workflow and Copilot setup steps to install the pinned OpenCode npm package so CI can validate the new built-in provider successfully.
- Aligned the dogfood docs with the current PHP requirement, provider setup, and default task ordering.
