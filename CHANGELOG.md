# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- Added `housekeeping:doctor` to validate config, writable paths, and enabled provider wiring from the CLI.
- Added `housekeeping:next` to show the next due housekeeping work in table and JSON formats.
- Added persisted per-run `results` and `errors` history so completed runs expose richer execution details.

### Changed

- Unified task config parsing for `housekeeping:list` and `housekeeping:next` so interval and priority fallbacks stay consistent.
- Hardened command output and regression coverage for `housekeeping:doctor`, `housekeeping:list`, `housekeeping:next`, and `TaskRunner` so the diff-based Infection CI job stays green.
