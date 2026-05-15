<?php

declare(strict_types=1);

$housekeepingRoot = dirname(__DIR__);
$targetProjectRoot = '/absolute/path/to/target-project';

$documentationFiles = [
    $targetProjectRoot . '/README.md',
];

$documentationContextFiles = [
    $targetProjectRoot . '/composer.json',
    $targetProjectRoot . '/AGENTS.md',
];

$todoFiles = [
    $targetProjectRoot . '/TODO.md',
];

return [
    'max_run_seconds' => 900,
    'max_tasks_per_run' => 4,
    'paths' => [
        'logs' => $housekeepingRoot . '/var/logs',
        'state' => $housekeepingRoot . '/var/state/state.json',
        'lock' => $housekeepingRoot . '/var/lock',
        'repository_root' => $targetProjectRoot,
    ],
    'tasks' => [
        'project:discover' => [
            'enabled' => true,
            'interval_seconds' => 21600,
            'priority' => 300,
            'ignored_paths' => [],
        ],
        'commits:learn' => [
            'enabled' => true,
            'interval_seconds' => 3600,
            'priority' => 200,
            'provider' => 'local-null-provider',
            'working_directory' => $targetProjectRoot,
            'max_commits' => 10,
        ],
        'blindspots:analyze' => [
            'enabled' => true,
            'interval_seconds' => 3600,
            'priority' => 150,
            'provider' => 'local-null-provider',
            'context_files' => array_merge($documentationFiles, $documentationContextFiles),
        ],
        'docs:refresh' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'priority' => 100,
            'provider' => 'local-null-provider',
            'input_files' => $documentationFiles,
            'context_files' => $documentationContextFiles,
        ],
        'todo:refine' => [
            'enabled' => true,
            'interval_seconds' => 21600,
            'priority' => 90,
            'provider' => 'local-null-provider',
            'input_files' => $todoFiles,
            'working_directory' => $targetProjectRoot,
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
    ],
];
