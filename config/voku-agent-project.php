<?php

declare(strict_types=1);

$housekeepingRoot = dirname(__DIR__);
$projectName = getenv('HOUSEKEEPING_AGENT_PROJECT') ?: 'agent-learning';
if (preg_match('/\Aagent-[a-z0-9][a-z0-9-]*\z/', $projectName) !== 1) {
    throw new RuntimeException('HOUSEKEEPING_AGENT_PROJECT must be an agent-* repository name.');
}

$configuredProjectRoot = getenv('HOUSEKEEPING_AGENT_PROJECT_ROOT');
$targetProjectRoot = $configuredProjectRoot !== false && $configuredProjectRoot !== ''
    ? rtrim($configuredProjectRoot, '/')
    : dirname($housekeepingRoot) . '/' . $projectName;
if (!is_dir($targetProjectRoot)) {
    throw new RuntimeException('Agent project directory not found: ' . $targetProjectRoot);
}
$preferredProviders = ['claude', 'agy', 'gemini', 'copilot', 'codex'];

$existingFiles = static function (array $paths): array {
    return array_values(array_filter($paths, 'is_file'));
};

$documentationFiles = $existingFiles([
    $targetProjectRoot . '/README.md',
    $targetProjectRoot . '/AGENTS.md',
]);
$documentationContextFiles = $existingFiles([
    $targetProjectRoot . '/composer.json',
    $targetProjectRoot . '/Makefile',
    $targetProjectRoot . '/phpstan.neon.dist',
    $targetProjectRoot . '/CHANGELOG.md',
    $targetProjectRoot . '/CLAUDE.md',
    $targetProjectRoot . '/MEMORY.md',
]);
$skillContextFiles = array_values(array_unique([
    ...$documentationFiles,
    ...$documentationContextFiles,
]));

$tasks = [
    'project:discover' => [
        'enabled' => true,
        'interval_seconds' => 21600,
        'priority' => 300,
    ],
    'commits:learn' => [
        'enabled' => true,
        'interval_seconds' => 3600,
        'priority' => 200,
        'provider' => 'auto',
        'preferred_providers' => $preferredProviders,
        'working_directory' => $targetProjectRoot,
        'max_commits' => 10,
    ],
    'blindspots:analyze' => [
        'enabled' => true,
        'interval_seconds' => 3600,
        'priority' => 150,
        'provider' => 'auto',
        'preferred_providers' => $preferredProviders,
        'context_files' => $skillContextFiles,
    ],
    'docs:refresh' => [
        'enabled' => $documentationFiles !== [],
        'interval_seconds' => 86400,
        'priority' => 100,
        'provider' => 'auto',
        'preferred_providers' => $preferredProviders,
        'input_files' => $documentationFiles,
        'context_files' => $documentationContextFiles,
    ],
    'skills:sync' => [
        'enabled' => true,
        'interval_seconds' => 86400,
        'priority' => 95,
        'provider' => 'auto',
        'preferred_providers' => $preferredProviders,
        'working_directory' => $targetProjectRoot,
        'selection_command' => [
            'bash',
            '-lc',
            'find skills -type f -name "SKILL.md" -print 2>/dev/null | sort | head -n 12',
        ],
        'context_files' => $skillContextFiles,
        'prompt' => 'Review the selected SKILL.md files against the current agent project code, README, commands, and package contracts. Fix stale paths, command examples, and workflow guidance inside the selected skill files only. Keep public behavior and compatibility unchanged.',
        'success_message' => 'Agent skill sync completed.',
        'timeout_seconds' => 180,
        'max_files' => 12,
    ],
];

$phpstanBinary = $targetProjectRoot . '/vendor/bin/phpstan';
if (is_file($targetProjectRoot . '/composer.json') && is_file($phpstanBinary)) {
    $tasks['phpdocs:refresh'] = [
        'enabled' => true,
        'interval_seconds' => 172800,
        'priority' => 85,
        'provider' => 'auto',
        'preferred_providers' => $preferredProviders,
        'working_directory' => $targetProjectRoot,
        'selection_command' => [
            'bash',
            '-lc',
            'git log --since="120 days ago" --name-only --pretty=format: -- "*.php" | awk \'NF && !seen[$0]++\' | head -n 12 || true',
        ],
        'context_files' => $skillContextFiles,
        'prompt' => 'Refresh PHPDoc and lightweight type annotations in the selected recent PHP files only. Prefer precise generics and array shapes without changing runtime behavior or public APIs. Run the repository PHPStan and PHPUnit commands for touched code before finishing.',
        'success_message' => 'Agent project PHPDoc refresh completed.',
        'timeout_seconds' => 180,
        'max_files' => 12,
    ];
    $tasks['phpstan:suggest-fixes'] = [
        'enabled' => true,
        'interval_seconds' => 43200,
        'priority' => 40,
        'provider' => 'auto',
        'preferred_providers' => $preferredProviders,
        'working_directory' => $targetProjectRoot,
        'command' => [
            PHP_BINARY,
            $phpstanBinary,
            'analyse',
            '--configuration=' . $targetProjectRoot . '/phpstan.neon.dist',
            '--memory-limit=512M',
            '--no-progress',
        ],
        'timeout_seconds' => 300,
    ];
}

return [
    'max_run_seconds' => 1500,
    'max_tasks_per_run' => 4,
    'paths' => [
        'logs' => $housekeepingRoot . '/var/logs/' . $projectName,
        'state' => $housekeepingRoot . '/var/state/' . $projectName . '-state.json',
        'lock' => $housekeepingRoot . '/var/lock/' . $projectName,
        'repository_root' => $targetProjectRoot,
    ],
    'tasks' => $tasks,
    'providers' => [
        'local-null-provider' => [
            'enabled' => true,
            'daily_budget' => 24,
            'cooldown_seconds' => 0,
        ],
        'claude' => [
            'enabled' => true,
            'daily_budget' => 5,
            'cooldown_seconds' => 3600,
            'timeout_seconds' => 1200,
            'command' => ['claude'],
            'append_yolo' => true,
            'resource_command' => ['claude', '--version'],
        ],
        'agy' => [
            'enabled' => true,
            'daily_budget' => 5,
            'cooldown_seconds' => 3600,
            'timeout_seconds' => 1200,
            'command' => ['agy'],
            'append_yolo' => true,
            'resource_command' => ['agy', '--version'],
        ],
        'gemini' => [
            'enabled' => true,
            'daily_budget' => 20,
            'cooldown_seconds' => 900,
            'timeout_seconds' => 1200,
            'command' => ['gemini'],
            'arguments' => ['--skip-trust', '--approval-mode', 'auto_edit'],
            'resource_command' => ['gemini-cli-usage', 'json'],
        ],
        'copilot' => [
            'enabled' => true,
            'daily_budget' => 5,
            'cooldown_seconds' => 3600,
            'timeout_seconds' => 600,
            'command' => ['copilot'],
            'model' => 'gpt-5.4',
            'arguments' => ['--allow-all-tools', '--no-ask-user'],
            'resource_command' => ['copilot-api', 'check-usage', '--json'],
        ],
        'codex' => [
            'enabled' => true,
            'daily_budget' => 10,
            'cooldown_seconds' => 1800,
            'timeout_seconds' => 600,
            'command' => ['codex'],
            'model' => 'gpt-5.5',
            'arguments' => ['--sandbox', 'workspace-write'],
            'resource_command' => ['codex-cli-usage', 'json'],
        ],
    ],
];
