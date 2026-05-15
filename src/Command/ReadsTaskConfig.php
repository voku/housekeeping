<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Command;

trait ReadsTaskConfig
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function taskConfig(array $config, string $taskName): array
    {
        $tasks = $config['tasks'] ?? null;
        if (!is_array($tasks)) {
            return [];
        }

        $taskConfig = $tasks[$taskName] ?? null;
        if (!is_array($taskConfig)) {
            return [];
        }
        /** @var array<string, mixed> $typedTaskConfig */
        $typedTaskConfig = $taskConfig;

        return $typedTaskConfig;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function intValue(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
