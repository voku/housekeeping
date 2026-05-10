<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Contract\ProviderBackedTask;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\RepositoryInspector;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

abstract readonly class AbstractProviderTask extends AbstractIntervalTask implements ProviderBackedTask
{
    public function __construct(
        int $intervalSeconds,
        private string $providerName,
        private RepositoryInspector $repositoryInspector = new RepositoryInspector(),
    ) {
        parent::__construct($intervalSeconds);
    }

    public function providerName(): string
    {
        return $this->providerName;
    }

    /**
     * @param array<string, mixed> $payload
     */
    final protected function executeProvider(RunContext $context, string $prompt, array $payload, string $successMessage): TaskResult
    {
        if ($context->dryRun) {
            return TaskResult::skipped(sprintf('Dry-run: %s was not sent to a provider.', $this->name()));
        }

        $provider = $context->provider($this->providerName);
        if ($provider === null) {
            return TaskResult::failure('Configured provider is not registered.', ['provider' => $this->providerName]);
        }

        $result = $provider->execute(new ProviderRequest($this->name(), $prompt, $payload));
        if (!$result->successful) {
            return TaskResult::failure($result->message, $result->context);
        }

        return TaskResult::success($successMessage, $result->context);
    }

    /**
     * @param list<string> $configuredPaths
     * @return array<string, string>
     */
    final protected function collectRepositoryFiles(RunContext $context, array $configuredPaths, string $metadataPath): array
    {
        $paths = [];
        foreach ($configuredPaths as $configuredPath) {
            if (is_string($configuredPath) && $configuredPath !== '') {
                $paths[$this->displayPath($context->repositoryRoot(), $configuredPath)] = $configuredPath;
            }
        }

        $discoveredPaths = $context->metadataValue($metadataPath);
        if (is_array($discoveredPaths)) {
            foreach ($discoveredPaths as $discoveredPath) {
                if (!is_string($discoveredPath) || $discoveredPath === '') {
                    continue;
                }

                $absolutePath = $context->repositoryRoot() . '/' . ltrim($discoveredPath, '/');
                $paths[$this->displayPath($context->repositoryRoot(), $absolutePath)] = $absolutePath;
            }
        }

        return $this->readFiles($context, array_values($paths));
    }

    /**
     * @param list<string> $paths
     * @return array<string, string>
     */
    final protected function readFiles(RunContext $context, array $paths): array
    {
        $relativePaths = [];
        $absolutePaths = [];

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            if (str_starts_with($path, $context->repositoryRoot() . '/')) {
                $relativePaths[] = $this->displayPath($context->repositoryRoot(), $path);
                continue;
            }
            if (str_starts_with($path, '/')) {
                $absolutePaths[] = $path;
                continue;
            }

            $relativePaths[] = ltrim($path, '/');
        }

        $documents = $this->repositoryInspector->readFiles($context->repositoryRoot(), array_values(array_unique($relativePaths)));
        foreach (array_values(array_unique($absolutePaths)) as $absolutePath) {
            if (!is_file($absolutePath)) {
                continue;
            }

            $contents = file_get_contents($absolutePath);
            if ($contents === false) {
                continue;
            }

            $documents[$this->displayPath($context->repositoryRoot(), $absolutePath)] = $contents;
        }

        ksort($documents);

        return $documents;
    }

    final protected function displayPath(string $repositoryRoot, string $path): string
    {
        $repositoryRoot = rtrim($repositoryRoot, '/');
        if (str_starts_with($path, $repositoryRoot . '/')) {
            return ltrim(substr($path, strlen($repositoryRoot)), '/');
        }

        return $path;
    }
}
