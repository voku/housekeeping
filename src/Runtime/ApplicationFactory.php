<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use HousekeepingAgentCron\Contract\HousekeepingTask;
use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Provider\CodexProvider;
use HousekeepingAgentCron\Provider\CopilotProvider;
use HousekeepingAgentCron\Provider\GeminiProvider;
use HousekeepingAgentCron\Provider\NullProvider;
use HousekeepingAgentCron\State\JsonStateStore;
use HousekeepingAgentCron\Task\CommitLearningTask;
use HousekeepingAgentCron\Task\DependencyAuditTask;
use HousekeepingAgentCron\Task\BlindSpotAnalysisTask;
use HousekeepingAgentCron\Task\DocumentationRefreshTask;
use HousekeepingAgentCron\Task\PhpstanFixSuggestionTask;
use HousekeepingAgentCron\Task\ProjectDiscoveryTask;
use HousekeepingAgentCron\Task\SlopScanTask;
use HousekeepingAgentCron\Task\TodoRefinementTask;
use RuntimeException;

final class ApplicationFactory
{
    public function __construct(private readonly ProcessExecutor $processExecutor = new ProcessExecutor())
    {
    }

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
        $definitions = $this->taskDefinitions($config);
        $tasks = [];
        foreach ($definitions as $name => $taskConfig) {
            if (($taskConfig['enabled'] ?? false) !== true) {
                continue;
            }
            $tasks[] = $this->createTask($name, $taskConfig);
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, ProviderAdapter>
     */
    public function providers(array $config): array
    {
        $providers = [];
        $nullProvider = new NullProvider();
        $providers[$nullProvider->name()] = $nullProvider;

        foreach ($this->providerDefinitions($config) as $name => $providerConfig) {
            if ($name === 'local-null-provider' || ($providerConfig['enabled'] ?? false) !== true) {
                continue;
            }
            $providers[$name] = $this->createProvider($name, $providerConfig, $config);
        }

        return $providers;
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
     * @return array<string, array<string, mixed>>
     */
    private function taskDefinitions(array $config): array
    {
        $tasks = $config['tasks'] ?? [];
        if (!is_array($tasks)) {
            throw new RuntimeException('Config key "tasks" must be an array.');
        }
        $typedTasks = [];
        foreach ($tasks as $name => $taskConfig) {
            if (!is_string($name) || !is_array($taskConfig)) {
                throw new RuntimeException('Each task definition must be a named array.');
            }
            /** @var array<string, mixed> $typedTaskConfig */
            $typedTaskConfig = $taskConfig;
            $typedTasks[$name] = $typedTaskConfig;
        }

        uksort($typedTasks, function (string $leftName, string $rightName) use ($typedTasks): int {
            $leftPriority = $this->intValue($typedTasks[$leftName]['priority'] ?? 0, 0);
            $rightPriority = $this->intValue($typedTasks[$rightName]['priority'] ?? 0, 0);
            if ($leftPriority === $rightPriority) {
                return strcmp($leftName, $rightName);
            }

            return $rightPriority <=> $leftPriority;
        });

        return $typedTasks;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, array<string, mixed>>
     */
    private function providerDefinitions(array $config): array
    {
        $providers = $config['providers'] ?? [];
        if (!is_array($providers)) {
            throw new RuntimeException('Config key "providers" must be an array.');
        }
        $typedProviders = [];
        foreach ($providers as $name => $providerConfig) {
            if (!is_string($name) || !is_array($providerConfig)) {
                throw new RuntimeException('Each provider definition must be a named array.');
            }
            /** @var array<string, mixed> $typedProviderConfig */
            $typedProviderConfig = $providerConfig;
            $typedProviders[$name] = $typedProviderConfig;
        }

        return $typedProviders;
    }

    /**
     * @param array<string, mixed> $taskConfig
     */
    private function createTask(string $name, array $taskConfig): HousekeepingTask
    {
        $intervalSeconds = $this->positiveInt($taskConfig['interval_seconds'] ?? 3600, 3600);
        $providerName = is_string($taskConfig['provider'] ?? null) ? $taskConfig['provider'] : 'local-null-provider';
        $inputFiles = $this->stringList($taskConfig['input_files'] ?? []);
        $contextFiles = $this->stringList($taskConfig['context_files'] ?? []);
        $workingDirectory = is_string($taskConfig['working_directory'] ?? null) ? $taskConfig['working_directory'] : dirname(__DIR__, 2);
        $command = $this->stringList($taskConfig['command'] ?? []);
        $timeoutSeconds = $this->positiveInt($taskConfig['timeout_seconds'] ?? 120, 120);
        $maxCommits = $this->positiveInt($taskConfig['max_commits'] ?? 10, 10);

        return match ($name) {
            'project:discover' => new ProjectDiscoveryTask($intervalSeconds),
            'commits:learn' => new CommitLearningTask($intervalSeconds, $providerName, $this->processExecutor, $workingDirectory, $maxCommits),
            'blindspots:analyze' => new BlindSpotAnalysisTask($intervalSeconds, $providerName, $contextFiles),
            'docs:refresh' => new DocumentationRefreshTask($intervalSeconds, $providerName, $inputFiles, $contextFiles),
            'todo:refine' => new TodoRefinementTask($intervalSeconds, $providerName, $inputFiles),
            'deps:audit' => new DependencyAuditTask($intervalSeconds, $providerName, $this->processExecutor, $workingDirectory, $command, $timeoutSeconds),
            'phpstan:suggest-fixes' => new PhpstanFixSuggestionTask($intervalSeconds, $providerName, $this->processExecutor, $workingDirectory, $command, $timeoutSeconds),
            'slop:scan' => new SlopScanTask($intervalSeconds, $providerName, $this->processExecutor, $workingDirectory, $command, $timeoutSeconds),
            default => throw new RuntimeException('Unknown task configuration: ' . $name),
        };
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @param array<string, mixed> $config
     */
    private function createProvider(string $name, array $providerConfig, array $config): ProviderAdapter
    {
        $command = $this->stringList($providerConfig['command'] ?? []);
        $arguments = $this->stringList($providerConfig['arguments'] ?? []);
        $workingDirectory = is_string($providerConfig['working_directory'] ?? null)
            ? $providerConfig['working_directory']
            : $this->path($config, 'repository_root', dirname(__DIR__, 2));
        $timeoutSeconds = $this->positiveInt($providerConfig['timeout_seconds'] ?? 600, 600);
        $appendYolo = ($providerConfig['append_yolo'] ?? true) !== false;

        return match ($name) {
            'codex' => new CodexProvider($this->processExecutor, $command, $arguments, $workingDirectory, $timeoutSeconds, $appendYolo),
            'gemini' => new GeminiProvider($this->processExecutor, $command, $arguments, $workingDirectory, $timeoutSeconds, $appendYolo),
            'copilot' => new CopilotProvider($this->processExecutor, $command, $arguments, $workingDirectory, $timeoutSeconds, $appendYolo),
            default => throw new RuntimeException('Unknown provider configuration: ' . $name),
        };
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

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function intValue(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }
}
