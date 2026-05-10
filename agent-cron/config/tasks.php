<?php

declare(strict_types=1);

return [
    'max_run_seconds' => 900,
    'max_tasks_per_run' => 3,
    'paths' => [
        'logs' => __DIR__ . '/../var/logs',
        'state' => __DIR__ . '/../var/state/state.json',
        'lock' => __DIR__ . '/../var/lock',
    ],
    'tasks' => [
        'docs:refresh' => [
            'enabled' => true,
            'interval_seconds' => 3600,
            'provider' => 'local-null-provider',
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
        ],
        'gemini' => [
            'enabled' => false,
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
