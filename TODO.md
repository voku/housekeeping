 ALIGN

User request: create a coding-agent cron in PHP with Symfony Console.
Goal: run background housekeeping tasks hourly and use unused subscription capacity from tools like Codex, Gemini, Copilot, etc. without doing anything stupid like bypassing limits, rotating accounts, or creating an automated quota-burning goblin.

Symfony Console is a good base because the component is explicitly meant for CLI commands, recurring jobs, cronjobs, imports, and batch jobs. Symfony Lock is the right boring tool to prevent overlapping executions, which is exactly the kind of boring that prevents production from becoming folk horror. 

Scope

Build a small standalone PHP 8.x Symfony Console app:

cron/systemd timer
    ↓
bin/agent-cron housekeeping:run
    ↓
Lock
    ↓
Task registry
    ↓
Quota / cooldown / runtime guard
    ↓
Provider adapters
    ↓
Logs + state

Not a daemon. Not a full Symfony app. Not Symfony Scheduler unless this later becomes Messenger-based. Symfony Scheduler is useful for application-level schedules, but for one hourly cron command, plain Console plus system cron is simpler and more deletable. 

DESIGN / PLAN

1. Package layout

./
├── bin/
│   └── agent-cron
├── config/
│   └── tasks.php
├── var/
│   ├── logs/
│   ├── state/
│   └── lock/
├── src/
│   ├── Command/
│   │   └── HousekeepingRunCommand.php
│   ├── Contract/
│   │   ├── HousekeepingTask.php
│   │   ├── ProviderAdapter.php
│   │   └── StateStore.php
│   ├── Provider/
│   │   ├── CodexProvider.php
│   │   ├── GeminiProvider.php
│   │   └── CopilotProvider.php
│   ├── Task/
│   │   ├── PhpstanFixSuggestionTask.php
│   │   ├── DependencyAuditTask.php
│   │   ├── TodoRefinementTask.php
│   │   └── DocumentationRefreshTask.php
│   ├── Runtime/
│   │   ├── TaskRunner.php
│   │   ├── RunContext.php
│   │   ├── TaskResult.php
│   │   ├── QuotaBudget.php
│   │   └── ProcessExecutor.php
│   └── State/
│       └── JsonStateStore.php
├── composer.json
└── crontab.example

2. Runtime rules

Each run should:

1. Acquire a lock.


2. Load task configuration.


3. Read previous state.


4. Skip tasks that are not due.


5. Skip providers that exceeded configured budget.


6. Run each selected task with timeout.


7. Persist result state.


8. Write structured logs.


9. Exit with meaningful code.



Symfony Lock provides exclusive execution so two cron invocations do not run the same critical section concurrently. That matters once a task modifies files, creates pull requests, or consumes API quota. 

3. Task model

Each task gets a small contract:

interface HousekeepingTask
{
    public function name(): string;

    public function isDue(RunContext $context): bool;

    public function run(RunContext $context): TaskResult;
}

No magic scheduler DSL at first. Humans already invented enough DSLs to avoid reading conditionals.

Example tasks:

Task	Purpose	Safe default

phpstan:suggest-fixes	Ask agent to analyze PHPStan output	dry-run only
docs:refresh	Update generated docs or TODO summaries	create patch only
deps:audit	Summarize outdated dependencies	no auto-upgrade
todo:refine	Convert messy TODOs into actionable items	write report
issues:triage	Categorize open issues	label proposal only


4. Provider model

Providers should be adapters, not hardwired task logic:

interface ProviderAdapter
{
    public function name(): string;

    public function isAvailable(RunContext $context): bool;

    public function execute(ProviderRequest $request): ProviderResult;
}

Initial adapters:

codex
gemini
copilot
local-null-provider

The local-null-provider is important for tests and dry-runs. Otherwise the test suite starts depending on paid AI tools, which is how software architecture becomes performance art.

5. Quota and safety budget

Use explicit config:

return [
    'max_run_seconds' => 900,
    'max_tasks_per_run' => 3,

    'providers' => [
        'codex' => [
            'enabled' => true,
            'daily_budget' => 10,
            'cooldown_seconds' => 1800,
        ],
        'gemini' => [
            'enabled' => true,
            'daily_budget' => 20,
            'cooldown_seconds' => 900,
        ],
        'copilot' => [
            'enabled' => false,
            'daily_budget' => 5,
            'cooldown_seconds' => 3600,
        ],
    ],
];

This is not “spend everything automatically”. It is “use a bounded amount for useful maintenance”. Tiny distinction, massive reduction in idiocy.

6. CLI commands

Implement these commands:

php bin/agent-cron housekeeping:run
php bin/agent-cron housekeeping:run --dry-run
php bin/agent-cron housekeeping:run --task=docs:refresh
php bin/agent-cron housekeeping:list
php bin/agent-cron housekeeping:state

7. Cron entry

Hourly:

7 * * * * cd /path/to/housekeeping && /usr/bin/php bin/agent-cron housekeeping:run >> var/logs/cron.log 2>&1

Use minute 7, not 0, to avoid every machine on earth waking up at the same minute like a cursed choir.

8. Exit codes

Code	Meaning

0	Success
1	One or more tasks failed
2	Invalid config
3	Lock already held
4	Provider unavailable
5	Runtime budget exceeded


9. First implementation iteration

Build only:

composer.json
bin/agent-cron
HousekeepingRunCommand
HousekeepingTask interface
TaskRunner
JsonStateStore
NullProvider
Example DocumentationRefreshTask
crontab.example

No real Codex/Gemini/Copilot integration yet. First prove the runner, locking, state, dry-run, and logs. Then provider adapters can be added without turning the core into soup.

Acceptance criteria

The first version is done when:

composer install
vendor/bin/phpstan analyse --level=max src tests
vendor/bin/phpunit
php bin/agent-cron housekeeping:list
php bin/agent-cron housekeeping:run --dry-run
php bin/agent-cron housekeeping:run

And:

[OK] Lock prevents concurrent runs
[OK] Dry-run does not call providers
[OK] State is persisted in var/state
[OK] Failed task does not hide error context
[OK] Runtime limit is enforced
[OK] Provider quota is checked before execution
[OK] Logs are structured enough for grep/jq

Recommended build order

DEFINE / SCOPE

Create the standalone package and contracts.

DESIGN / PLAN

Add lock, state, quota budget, and task registry.

CODE / FEEDBACK

Implement one fake provider and one safe task.

VERIFY

Add PHPUnit tests for:

lock already held
task skipped when not due
quota exceeded
dry-run
task failure result
state persistence

EXTEND

Only after that add real provider adapters. Otherwise the first “working” version will be a pile of shell commands wearing a trench coat.
