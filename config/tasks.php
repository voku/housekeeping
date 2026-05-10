<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);

return [
    'max_run_seconds' => 900,
    'max_tasks_per_run' => 3,
    'paths' => [
        'logs' => $packageRoot . '/var/logs',
        'state' => $packageRoot . '/var/state/state.json',
        'lock' => $packageRoot . '/var/lock',
    ],
    'tasks' => [
        'docs:refresh' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'provider' => 'local-null-provider',
            'input_files' => [$packageRoot . '/TODO.md'],
        ],
        'todo:refine' => [
            'enabled' => true,
            'interval_seconds' => 21600,
            'provider' => 'local-null-provider',
            'input_files' => [$packageRoot . '/TODO.md'],
        ],
        'deps:audit' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'provider' => 'local-null-provider',
            'working_directory' => $packageRoot,
            'command' => ['composer', 'outdated', '--direct', '--format=json'],
        ],
        'phpstan:suggest-fixes' => [
            'enabled' => true,
            'interval_seconds' => 43200,
            'provider' => 'local-null-provider',
            'working_directory' => $packageRoot,
            'command' => ['vendor/bin/phpstan', 'analyse', '--level=max', 'src', 'tests', '--no-progress'],
            'timeout_seconds' => 300,
        ],
        'slop:scan' => [
            'enabled' => true,
            'interval_seconds' => 43200,
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
            'working_directory' => $packageRoot,
            'command' => ['codex', 'exec'],
        ],
        'gemini' => [
            'enabled' => false,
            'daily_budget' => 20,
            'cooldown_seconds' => 900,
            'timeout_seconds' => 600,
            'working_directory' => $packageRoot,
            'command' => ['gemini'],
        ],
        'copilot' => [
            'enabled' => false,
            'daily_budget' => 5,
            'cooldown_seconds' => 3600,
            'timeout_seconds' => 600,
            'working_directory' => $packageRoot,
            'command' => ['copilot'],
        ],
    ],
];
