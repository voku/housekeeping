<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);

$blindSpotContextFiles = [
    $packageRoot . '/README.md',
    $packageRoot . '/QUICKSTART.md',
    $packageRoot . '/AGENTS.md',
    $packageRoot . '/TODO.md',
    $packageRoot . '/config/tasks.php',
    $packageRoot . '/crontab.example',
];

$documentationFiles = [
    $packageRoot . '/README.md',
    $packageRoot . '/QUICKSTART.md',
    $packageRoot . '/AGENTS.md',
];

$documentationContextFiles = [
    $packageRoot . '/composer.json',
    $packageRoot . '/config/tasks.php',
    $packageRoot . '/bin/agent-cron',
    $packageRoot . '/crontab.example',
    $packageRoot . '/TODO.md',
];

$skillContextFiles = [
    $packageRoot . '/README.md',
    $packageRoot . '/QUICKSTART.md',
    $packageRoot . '/AGENTS.md',
    $packageRoot . '/TODO.md',
    $packageRoot . '/composer.json',
    $packageRoot . '/config/tasks.php',
];

$todoFiles = [
    $packageRoot . '/TODO.md',
];

return [
    'max_run_seconds' => 900,
    'max_tasks_per_run' => 4,
    'paths' => [
        'logs' => $packageRoot . '/var/logs',
        'state' => $packageRoot . '/var/state/state.json',
        'lock' => $packageRoot . '/var/lock',
        'repository_root' => $packageRoot,
    ],
    'tasks' => [
        'project:discover' => [
            'enabled' => true,
            'interval_seconds' => 21600,
            'priority' => 300,
        ],
        'commits:learn' => [
            'enabled' => true,
            'interval_seconds' => 3600,
            'priority' => 200,
            'provider' => 'local-null-provider',
            'working_directory' => $packageRoot,
            'max_commits' => 10,
        ],
        'blindspots:analyze' => [
            'enabled' => true,
            'interval_seconds' => 3600,
            'priority' => 150,
            'provider' => 'local-null-provider',
            'context_files' => $blindSpotContextFiles,
        ],
        'docs:refresh' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'priority' => 100,
            'provider' => 'local-null-provider',
            'input_files' => $documentationFiles,
            'context_files' => $documentationContextFiles,
        ],
        'skills:sync' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'priority' => 95,
            'provider' => 'local-null-provider',
            'working_directory' => $packageRoot,
            'selection_command' => [
                'bash',
                '-lc',
                'find . -path "./.git" -prune -o -path "./vendor" -prune -o -path "./var" -prune -o -type f -name "SKILL.md" -print | sed "s#^\\./##" | head -n 12',
            ],
            'context_files' => $skillContextFiles,
            'prompt' => 'Review the selected SKILL.md files and keep them aligned with the current repository code, commands, AGENTS routing, and TODO workflow when present. Fix stale task names, file paths, validation commands, and workflow guidance inside the skill files only. Do not change application code as part of this task.',
            'success_message' => 'Skill file sync completed.',
            'timeout_seconds' => 120,
            'max_files' => 12,
        ],
        'todo:refine' => [
            'enabled' => true,
            'interval_seconds' => 21600,
            'priority' => 90,
            'provider' => 'local-null-provider',
            'input_files' => $todoFiles,
        ],
        'self-improve:housekeeping' => [
            'enabled' => true,
            'interval_seconds' => 1,
            'priority' => 80,
            'provider' => 'local-null-provider',
            'working_directory' => $packageRoot,
            'scope_paths' => ['src', 'config', 'tests', 'README.md', 'QUICKSTART.md'],
            'context_files' => [
                $packageRoot . '/README.md',
                $packageRoot . '/QUICKSTART.md',
                $packageRoot . '/config/tasks.php',
                $packageRoot . '/src/Runtime/TaskRunner.php',
                $packageRoot . '/src/Task/BlindSpotAnalysisTask.php',
                $packageRoot . '/src/Task/TodoRefinementTask.php',
            ],
            'validation_commands' => [
                [PHP_BINARY, $packageRoot . '/vendor/bin/phpstan', 'analyse', '--level=max', 'src', 'tests', '--no-progress'],
                [PHP_BINARY, $packageRoot . '/vendor/bin/phpunit'],
                [PHP_BINARY, $packageRoot . '/bin/agent-cron', 'housekeeping:list', '--config=' . $packageRoot . '/config/tasks.php'],
                [PHP_BINARY, $packageRoot . '/bin/agent-cron', 'housekeeping:state', '--config=' . $packageRoot . '/config/tasks.php'],
            ],
            'run_threshold' => 10,
            'recent_run_limit' => 10,
            'log_entry_limit' => 60,
            'timeout_seconds' => 1200,
        ],
        'deps:audit' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'priority' => 50,
            'provider' => 'local-null-provider',
            'working_directory' => $packageRoot,
            'command' => ['composer', 'outdated', '--direct', '--format=json'],
        ],
        'phpstan:suggest-fixes' => [
            'enabled' => true,
            'interval_seconds' => 43200,
            'priority' => 40,
            'provider' => 'local-null-provider',
            'working_directory' => $packageRoot,
            'command' => ['vendor/bin/phpstan', 'analyse', '--level=max', 'src', 'tests', '--no-progress'],
            'timeout_seconds' => 300,
        ],
        'slop:scan' => [
            'enabled' => true,
            'interval_seconds' => 43200,
            'priority' => 30,
            'provider' => 'local-null-provider',
            'working_directory' => $packageRoot,
            'command' => [
                PHP_BINARY,
                $packageRoot . '/tools/slop-scan.phar',
                'scan',
                '.',
                '--json',
                '--config-file',
                $packageRoot . '/slop-scan.config.json',
            ],
            'timeout_seconds' => 300,
        ],
    ],
    'providers' => [
        'local-null-provider' => [
            'enabled' => true,
            'daily_budget' => 24,
            'cooldown_seconds' => 0,
        ],
        'codex' => [
            'enabled' => false,
            'daily_budget' => 10,
            'cooldown_seconds' => 1800,
            'timeout_seconds' => 600,
            'command' => ['codex'],
            'model' => 'gpt-5.4',
            'resource_command' => ['codex-cli-usage', 'json'],
        ],
        'gemini' => [
            'enabled' => false,
            'daily_budget' => 20,
            'cooldown_seconds' => 900,
            'timeout_seconds' => 600,
            'command' => ['gemini'],
            'resource_command' => ['gemini-cli-usage', 'json'],
        ],
        'copilot' => [
            'enabled' => false,
            'daily_budget' => 5,
            'cooldown_seconds' => 3600,
            'timeout_seconds' => 600,
            'command' => ['copilot'],
            'resource_command' => ['copilot-api', 'check-usage', '--json'],
        ],
        'claude' => [
            'enabled' => false,
            'daily_budget' => 5,
            'cooldown_seconds' => 3600,
            'timeout_seconds' => 600,
            'command' => ['claude'],
            'resource_command' => ['claude', '--version'],
        ],
        'opencode' => [
            'enabled' => false,
            'daily_budget' => 10,
            'cooldown_seconds' => 1800,
            'timeout_seconds' => 600,
            'command' => ['opencode'],
            'model' => 'opencode/minimax-m2.5-free',
        ],
    ],
];
