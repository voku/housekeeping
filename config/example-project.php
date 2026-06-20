<?php

declare(strict_types=1);

$housekeepingRoot = dirname(__DIR__);
// CHANGE ME: replace this with the absolute path to the repository that Housekeeping should maintain.
$targetProjectRoot = '/var/www/html';

$documentationFiles = array_values(array_filter([
    $targetProjectRoot . '/README.md',
    $targetProjectRoot . '/docs/Decision-Log.md',
], 'is_file'));

$documentationContextFiles = array_values(array_filter([
    $targetProjectRoot . '/composer.json',
    $targetProjectRoot . '/AGENTS.md',
    $targetProjectRoot . '/TODO.md',
], 'is_file'));

$skillContextFiles = array_values(array_filter([
    $targetProjectRoot . '/README.md',
    $targetProjectRoot . '/composer.json',
    $targetProjectRoot . '/AGENTS.md',
    $targetProjectRoot . '/TODO.md',
], 'is_file'));

$todoFiles = array_values(array_filter([
    $targetProjectRoot . '/TODO.md',
], 'is_file'));

return [
    'max_run_seconds' => 900,
    'max_tasks_per_run' => 4,
    'paths' => [
        'logs' => $housekeepingRoot . '/var/logs',
        'state' => $housekeepingRoot . '/var/state/example-project-state.json',
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
            'context_commands' => [
                ['bash', '-lc', 'printf "Board snapshot:\n" && sed -n "1,120p" TODO.md || true'],
                ['bash', '-lc', 'printf "\nDecision Log:\n" && sed -n "1,120p" docs/Decision-Log.md || true'],
            ],
            'validation_command' => ['bash', '-lc', 'git diff --stat -- TODO.md'],
        ],
        'phpdocs:refresh' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'priority' => 85,
            'provider' => 'auto',
            'preferred_providers' => ['gemini', 'copilot'],
            'working_directory' => $targetProjectRoot,
            'selection_command' => [
                'bash',
                '-lc',
                'git log --since="120 days ago" --name-only --pretty=format: -- "*.php" | awk \'NF && !seen[$0]++\' | head -n 12 || true',
            ],
            'context_files' => $documentationContextFiles,
            'prompt' => 'Refresh PHPDoc blocks for the selected PHP files. Keep behavior unchanged and only make edits that clarify accurate types, shapes, and side effects.',
            'success_message' => 'PHPDoc refresh completed.',
            'timeout_seconds' => 120,
            'max_files' => 12,
        ],
        'magic-numbers:reduce' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'priority' => 80,
            'provider' => 'auto',
            'preferred_providers' => ['gemini', 'copilot'],
            'working_directory' => $targetProjectRoot,
            'selection_command' => [
                'bash',
                '-lc',
                'git grep -l -iE "TODO.*(magic number|constant|hardcoded)" -- "*.php" | head -n 10 || true',
            ],
            'context_files' => $documentationContextFiles,
            'prompt' => 'Review the selected PHP files and replace safe repeated magic numbers or hardcoded flags with existing named constants where that improves clarity without changing behavior.',
            'success_message' => 'Magic number cleanup completed.',
            'timeout_seconds' => 120,
            'max_files' => 10,
        ],
        'todo-comments:cleanup' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'priority' => 75,
            'provider' => 'auto',
            'preferred_providers' => ['gemini', 'copilot'],
            'working_directory' => $targetProjectRoot,
            'selection_command' => [
                'bash',
                '-lc',
                'git grep -l -E "TODO\\s*@" -- "*.php" | head -n 10 || true',
            ],
            'context_files' => array_merge($documentationContextFiles, $todoFiles),
            'prompt' => 'Review stale TODO owner comments in the selected PHP files and either complete the tiny cleanup, rewrite the note to be actionable, or remove comments that are no longer useful.',
            'success_message' => 'TODO comment cleanup completed.',
            'timeout_seconds' => 120,
            'max_files' => 10,
        ],
        'small-refactors:polish' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'priority' => 70,
            'provider' => 'auto',
            'preferred_providers' => ['gemini', 'copilot'],
            'working_directory' => $targetProjectRoot,
            'selection_command' => [
                'bash',
                '-lc',
                'git log --since="90 days ago" --name-only --pretty=format: -- "*.php" | awk \'NF && !seen[$0]++\' | head -n 10 || true',
            ],
            'context_files' => $documentationContextFiles,
            'prompt' => 'Apply only tiny, low-risk refactors to the selected PHP files. Keep behavior identical, avoid renaming public APIs unless required, and regenerate the autoloader map if you have to add, move, or rename any class-like symbol.',
            'success_message' => 'Small refactors completed.',
            'timeout_seconds' => 120,
            'max_files' => 10,
        ],
        'deps:audit' => [
            'enabled' => true,
            'interval_seconds' => 86400,
            'priority' => 50,
            'provider' => 'local-null-provider',
            'working_directory' => $targetProjectRoot,
            'command' => ['composer', 'outdated', '--direct', '--format=json'],
        ],
        // The scheduled "sleep cycle". Opt-in: enable it and point 'command' at
        // your repository's deterministic consolidation step, which must only
        // write reviewable candidate proposals (never approve durable guidance).
        'learnings:consolidate' => [
            'enabled' => false,
            'interval_seconds' => 86400,
            'priority' => 45,
            'working_directory' => $targetProjectRoot,
            'command' => [],
            'timeout_seconds' => 300,
        ],
        'slop:scan' => [
            'enabled' => true,
            'interval_seconds' => 43200,
            'priority' => 30,
            'provider' => 'local-null-provider',
            'working_directory' => $targetProjectRoot,
            'command' => [
                'docker',
                'compose',
                'exec',
                '-T',
                'php',
                'bash',
                '-lc',
                'cd /var/www/html && php -d memory_limit=2G vendor/slop-scan.phar scan /var/www/html --config-file infra/githooks/slop-scan.config.json --cache-file /tmp/example-project-slop-scan-cache.json --baseline-file /var/www/html/infra/githooks/slop-scan-baseline.toon --min-score 2 --json',
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
