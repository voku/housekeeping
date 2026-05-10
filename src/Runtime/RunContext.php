<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Contract\StateStore;

final class RunContext
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     * @param array<string, ProviderAdapter> $providers
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly ?string $taskFilter,
        public readonly int $startedAt,
        public readonly array $config,
        private array $state,
        private readonly StateStore $stateStore,
        private readonly JsonLogger $logger,
        private readonly array $providers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    public function stateValue(string $path): mixed
    {
        $value = $this->state;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function setStateValue(string $path, mixed $newValue): void
    {
        $segments = explode('.', $path);
        $state = &$this->state;
        foreach ($segments as $segment) {
            if (!isset($state[$segment]) || !is_array($state[$segment])) {
                $state[$segment] = [];
            }
            $state = &$state[$segment];
        }
        $state = $newValue;
    }

    public function saveState(): void
    {
        $this->stateStore->save($this->state);
    }

    public function logger(): JsonLogger
    {
        return $this->logger;
    }

    public function repositoryRoot(): string
    {
        $paths = $this->config['paths'] ?? null;
        if (is_array($paths) && is_string($paths['repository_root'] ?? null) && $paths['repository_root'] !== '') {
            return rtrim($paths['repository_root'], '/');
        }

        return dirname(__DIR__, 2);
    }

    public function metadataValue(string $path): mixed
    {
        return $this->stateValue('metadata.' . $path);
    }

    public function setMetadataValue(string $path, mixed $value): void
    {
        $this->setStateValue('metadata.' . $path, $value);
    }

    public function provider(string $name): ?ProviderAdapter
    {
        return $this->providers[$name] ?? null;
    }

    public function elapsedSeconds(): int
    {
        return time() - $this->startedAt;
    }
}
