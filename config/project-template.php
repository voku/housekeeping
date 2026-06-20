<?php

declare(strict_types=1);

$housekeepingRoot = dirname(__DIR__);
// CHANGE ME: replace this with the absolute path to the repository that Housekeeping should maintain.
$targetProjectRoot = '/absolute/path/to/YOUR-PROJECT-HERE';

$documentationFiles = [
    $targetProjectRoot . '/README.md',
];

$documentationContextFiles = [
    $targetProjectRoot . '/composer.json',
    $targetProjectRoot . '/AGENTS.md',
];

$skillContextFiles = array_values(array_filter([
    $targetProjectRoot . '/README.md',
    $targetProjectRoot . '/composer.json',
    $targetProjectRoot . '/AGENTS.md',
], 'is_file'));

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
        'skills:sync' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'priority' => 95,
            'provider' => 'local-null-provider',
            'working_directory' => $targetProjectRoot,
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
            'append_yolo' => true,
            'resource_command' => ['claude', '--version'],
        ],
        'agy' => [
            'enabled' => false,
            'daily_budget' => 5,
            'cooldown_seconds' => 3600,
            'timeout_seconds' => 600,
            'command' => ['agy'],
            'append_yolo' => true,
            'resource_command' => ['agy', '--version'],
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
