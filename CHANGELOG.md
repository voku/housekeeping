# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- Added `housekeeping:doctor` to validate config, writable paths, and enabled provider wiring from the CLI.
- Added `housekeeping:next` to show the next due housekeeping work in table and JSON formats.
- Added persisted per-run `results` and `errors` history so completed runs expose richer execution details.
- Added `config/project-template.php` so local installs can copy a target-project config instead of rewriting the dogfood config from scratch.
- Added built-in OpenCode provider support, including default config entries, CLI smoke coverage, and docs for trying the bundled free-tier model selection.

### Changed

- Unified task config parsing for `housekeeping:list` and `housekeeping:next` so interval and priority fallbacks stay consistent.
- Hardened command output and regression coverage for `housekeeping:doctor`, `housekeeping:list`, `housekeeping:next`, and `TaskRunner` so the diff-based Infection CI job stays green.
- Extended `housekeeping:doctor` so it now fails fast when enabled tasks reference missing `input_files` or `context_files`, and restored the dogfood `TODO.md` target so `todo:refine` has a real tracked document again.
- Simplified README and Quick Start onboarding so local installs can export `HOUSEKEEPING_CONFIG`, run the doctor/list/dry-run flow quickly, and point Housekeeping at a destination project with fewer edits.
- Tightened the dogfood `self-improve:housekeeping` validation pipeline so accepted changes must now pass PHPStan in addition to PHPUnit and the existing CLI smoke checks.
- Updated the provider CLI smoke workflow to install OpenCode and export its bin directory so CI can validate the new built-in provider successfully.
