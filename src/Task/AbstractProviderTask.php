<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Contract\ProviderBackedTask;
use HousekeepingAgentCron\Runtime\ProviderOutputNormalizer;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\RepositoryInspector;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

abstract readonly class AbstractProviderTask extends AbstractIntervalTask implements ProviderBackedTask
{
    /**
     * @param list<string> $preferredProviderNames
     */
    public function __construct(
        int $intervalSeconds,
        private string $providerName,
        private array $preferredProviderNames = [],
        private RepositoryInspector $repositoryInspector = new RepositoryInspector(),
        private ProviderOutputNormalizer $providerOutputNormalizer = new ProviderOutputNormalizer(),
    ) {
        parent::__construct($intervalSeconds);
    }

    public function providerName(): string
    {
        return $this->providerName;
    }

    /**
     * @return list<string>
     */
    public function preferredProviderNames(): array
    {
        return $this->preferredProviderNames;
    }

    /**
     * @param array<string, mixed> $payload
     */
    final protected function executeProvider(RunContext $context, string $prompt, array $payload, string $successMessage): TaskResult
    {
        if ($context->dryRun) {
            return TaskResult::skipped(sprintf('Dry-run: %s was not sent to a provider.', $this->name()));
        }

        $providerName = $this->resolvedProviderName($context);
        $provider = $context->provider($providerName);
        if ($provider === null) {
            return TaskResult::failure('Configured provider is not registered.', ['provider' => $providerName]);
        }

        $result = $provider->execute(new ProviderRequest($this->name(), $prompt, $payload));
        $normalizedContext = $this->providerOutputNormalizer->normalize([...$result->context, 'provider' => $providerName]);
        if (!$result->successful) {
            return TaskResult::failure($result->message, $normalizedContext);
        }

        $taskResult = TaskResult::success($successMessage, $normalizedContext);
        $this->persistProviderMetadata($context, 'task_provider_results.' . $this->name(), $taskResult, $providerName);

        return $taskResult;
    }

    /**
     * @return array<string, mixed>
     */
    final protected function sharedMetadata(RunContext $context): array
    {
        return [
            'project_metadata' => $context->metadataValue('project'),
            'learning_metadata' => $this->providerMetadataSummary($context->metadataValue('learning')),
            'blind_spot_metadata' => $this->providerMetadataSummary($context->metadataValue('blind_spots')),
        ];
    }

    /**
     * @param list<string> $configuredPaths
     * @return array<string, string>
     */
    final protected function collectRepositoryFiles(RunContext $context, array $configuredPaths, string $metadataPath): array
    {
        $paths = $this->configuredRepositoryPaths($context, $configuredPaths);

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
     * @param list<string> $configuredPaths
     * @return array<string, string>
     */
    final protected function configuredRepositoryFiles(RunContext $context, array $configuredPaths): array
    {
        return $this->readFiles($context, array_values($this->configuredRepositoryPaths($context, $configuredPaths)));
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
            if ($path === '') {
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

    /**
     * @param list<string> $configuredPaths
     * @return array<string, string>
     */
    final protected function configuredRepositoryPaths(RunContext $context, array $configuredPaths): array
    {
        $paths = [];
        foreach ($configuredPaths as $configuredPath) {
            if ($configuredPath === '') {
                continue;
            }

            $paths[$this->displayPath($context->repositoryRoot(), $configuredPath)] = $configuredPath;
        }

        return $paths;
    }

    final protected function persistProviderMetadata(RunContext $context, string $metadataPath, TaskResult $result, ?string $providerName = null): void
    {
        if ($context->dryRun || !$result->successful || $result->skipped) {
            return;
        }

        $providerOutput = $result->context['provider_output'] ?? null;
        $providerOutput = is_array($providerOutput) ? $providerOutput : [];

        $context->setMetadataValue($metadataPath . '.last_provider', $providerName ?? $this->resolvedProviderName($context));
        $context->setMetadataValue($metadataPath . '.last_recorded_at', time());
        $context->setMetadataValue($metadataPath . '.last_stdout', $this->trimmedString($result->context['stdout'] ?? null));
        $context->setMetadataValue($metadataPath . '.last_stderr', $this->trimmedString($result->context['stderr'] ?? null));
        $context->setMetadataValue($metadataPath . '.last_summary', $this->trimmedString($providerOutput['summary'] ?? null));
        $context->setMetadataValue($metadataPath . '.last_summaries', $this->stringList($providerOutput['summaries'] ?? []));
        $context->setMetadataValue($metadataPath . '.last_patches', $this->patchList($providerOutput['patches'] ?? []));
        $context->setMetadataValue($metadataPath . '.last_metadata', $this->associativeArray($providerOutput['metadata'] ?? []));
    }

    private function resolvedProviderName(RunContext $context): string
    {
        $providerName = $context->runtimeValue('task_provider_routes.' . $this->name() . '.resolved_provider');

        return is_string($providerName) && $providerName !== '' ? $providerName : $this->providerName;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function providerMetadataSummary(mixed $value): array
    {
        $metadata = $this->associativeArray($value);
        if ($metadata === []) {
            return [];
        }

        unset($metadata['last_stdout'], $metadata['last_stderr']);

        foreach (['last_provider_output', 'last_summary'] as $key) {
            $string = $this->trimmedString($metadata[$key] ?? null);
            if ($string === null) {
                unset($metadata[$key]);
                continue;
            }
            $metadata[$key] = $this->truncateString($string, 4000);
        }

        $summaries = $this->stringList($metadata['last_summaries'] ?? []);
        if ($summaries !== []) {
            $metadata['last_summaries'] = array_map(
                fn (string $summary): string => $this->truncateString($summary, 2000),
                array_slice($summaries, 0, 5),
            );
        }

        return $metadata;
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
            $string = $this->trimmedString($item);
            if ($string !== null) {
                $items[] = $string;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param mixed $value
     * @return list<array{summary: string|null, paths: list<string>, diff_present: bool}>
     */
    private function patchList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $patches = [];
        foreach ($value as $patch) {
            if (!is_array($patch)) {
                continue;
            }

            $summary = $this->trimmedString($patch['summary'] ?? null);
            $paths = $this->stringList($patch['paths'] ?? []);
            foreach (['path', 'file', 'file_path', 'target'] as $key) {
                $path = $this->trimmedString($patch[$key] ?? null);
                if ($path !== null) {
                    $paths[] = $path;
                }
            }
            $paths = array_values(array_unique($paths));
            $diffPresent = ($patch['diff_present'] ?? false) === true;
            if ($summary === null && $paths === [] && !$diffPresent) {
                continue;
            }

            $patches[] = [
                'summary' => $summary,
                'paths' => $paths,
                'diff_present' => $diffPresent,
            ];
        }

        return $patches;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function associativeArray(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [];
        }

        $typed = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $typed[$key] = $item;
        }

        return $typed;
    }

    private function trimmedString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function truncateString(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(substr($value, 0, $maxLength - 15)) . ' [truncated]';
    }
}
