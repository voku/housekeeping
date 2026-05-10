<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use HousekeepingAgentCron\Contract\HousekeepingTask;
use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Provider\NullProvider;
use HousekeepingAgentCron\State\JsonStateStore;
use HousekeepingAgentCron\Task\DocumentationRefreshTask;
use RuntimeException;

final class ApplicationFactory
{
    /**
     * @return array<string, mixed>
     */
    public function loadConfig(string $configFile): array
    {
        $config = require $configFile;
        if (!is_array($config)) {
            throw new RuntimeException('Config file must return an array.');
        }
        /** @var array<string, mixed> $typedConfig */
        $typedConfig = $config;

        return $typedConfig;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<HousekeepingTask>
     */
    public function tasks(array $config): array
    {
        $tasks = $config['tasks'] ?? [];
        if (!is_array($tasks)) {
            throw new RuntimeException('Config key "tasks" must be an array.');
        }
        $docs = $tasks['docs:refresh'] ?? [];
        if (!is_array($docs) || ($docs['enabled'] ?? false) !== true) {
            return [];
        }

        return [
            new DocumentationRefreshTask(
                $this->positiveInt($docs['interval_seconds'] ?? 3600, 3600),
                is_string($docs['provider'] ?? null) ? $docs['provider'] : 'local-null-provider',
            ),
        ];
    }

    /**
     * @return array<string, ProviderAdapter>
     */
    public function providers(): array
    {
        $nullProvider = new NullProvider();

        return [$nullProvider->name() => $nullProvider];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function stateStore(array $config): JsonStateStore
    {
        return new JsonStateStore($this->path($config, 'state', __DIR__ . '/../../var/state/state.json'));
    }

    /**
     * @param array<string, mixed> $config
     */
    public function logger(array $config): JsonLogger
    {
        return new JsonLogger(rtrim($this->path($config, 'logs', __DIR__ . '/../../var/logs'), '/') . '/housekeeping.log');
    }

    /**
     * @param array<string, mixed> $config
     */
    public function lockDir(array $config): string
    {
        return $this->path($config, 'lock', __DIR__ . '/../../var/lock');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function path(array $config, string $key, string $default): string
    {
        $paths = $config['paths'] ?? [];
        if (is_array($paths) && is_string($paths[$key] ?? null)) {
            return $paths[$key];
        }

        return $default;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }
}
