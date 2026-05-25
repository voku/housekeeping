<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;
use JsonException;

final readonly class DependencyAuditTask extends AbstractProviderTask
{
    /**
     * @param list<string> $command
     * @param list<string> $preferredProviderNames
     */
    public function __construct(
        int $intervalSeconds,
        string $providerName,
        private ProcessExecutor $processExecutor,
        private string $workingDirectory,
        private array $command,
        private int $timeoutSeconds,
        array $preferredProviderNames = [],
    ) {
        parent::__construct($intervalSeconds, $providerName, $preferredProviderNames);
    }

    public function name(): string
    {
        return 'deps:audit';
    }

    public function run(RunContext $context): TaskResult
    {
        if (!is_file($this->workingDirectory . '/composer.json')) {
            return TaskResult::skipped('Dependency audit skipped because no composer.json was found.');
        }
        if ($context->dryRun) {
            return TaskResult::skipped('Dry-run: deps:audit was not sent to a provider.');
        }

        $process = $this->processExecutor->execute($this->command, $this->workingDirectory, $this->timeoutSeconds);
        if ($process->timedOut) {
            return TaskResult::failure('Dependency audit command timed out.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
            ]);
        }
        if (!$process->successful()) {
            $context = [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
                'exit_code' => $process->exitCode,
                'stderr' => $process->stderr,
                'stdout' => $process->stdout,
            ];
            if ($process->exceptionMessage !== null) {
                $context['exception'] = $process->exceptionMessage;
            }

            return TaskResult::failure('Dependency audit command failed.', $context);
        }

        $report = $this->decodeReport($process->stdout);
        if ($report === null) {
            return TaskResult::failure('Dependency audit output was not valid JSON.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
                'stdout' => $process->stdout,
                'stderr' => $process->stderr,
            ]);
        }

        $packages = $this->auditPackages($report);
        if ($packages === []) {
            return TaskResult::skipped('No direct dependency updates detected.');
        }

        $auditSummary = $this->auditSummary($packages);

        return $this->executeProvider(
            $context,
            'Summarize the dependency audit output and propose safe manual follow-up actions only. Prioritize abandoned packages and major-version updates before semver-safe updates, and use the structured audit_summary plus audit_packages payload instead of paraphrasing raw JSON where possible. Use the learned repository patterns and recent blind-spot guidance to prioritize follow-up. Do not upgrade anything automatically.',
            [
                'working_directory' => $this->workingDirectory,
                'command' => $process->command,
                'audit_summary' => $auditSummary,
                'audit_packages' => $packages,
                'stdout' => $process->stdout,
                'stderr' => $process->stderr,
                ...$this->sharedMetadata($context),
            ],
            $this->successMessage($auditSummary),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeReport(string $stdout): ?array
    {
        try {
            $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($report)) {
            return null;
        }

        $typedReport = [];
        foreach ($report as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $typedReport[$key] = $value;
        }

        return $typedReport;
    }

    /**
     * @param array<string, mixed> $report
     * @return list<array{
     *     name: string,
     *     version: string|null,
     *     latest: string|null,
     *     latest_status: string|null,
     *     abandoned: bool,
     *     replacement: string|null
     * }>
     */
    private function auditPackages(array $report): array
    {
        $installed = $report['installed'] ?? null;
        if (!is_array($installed)) {
            return [];
        }

        $packages = [];

        foreach ($installed as $package) {
            if (!is_array($package)) {
                continue;
            }

            $name = $this->trimmedString($package['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $abandonedValue = $package['abandoned'] ?? false;
            $replacement = null;
            $abandoned = false;
            if (is_string($abandonedValue)) {
                $replacement = $this->trimmedString($abandonedValue);
                $abandoned = true;
            } elseif ($abandonedValue === true) {
                $abandoned = true;
            }

            $packages[] = [
                'name' => $name,
                'version' => $this->trimmedString($package['version'] ?? null),
                'latest' => $this->trimmedString($package['latest'] ?? null),
                'latest_status' => $this->trimmedString($package['latest-status'] ?? null),
                'abandoned' => $abandoned,
                'replacement' => $replacement,
            ];
        }

        return $packages;
    }

    /**
     * @param list<array{
     *     name: string,
     *     version: string|null,
     *     latest: string|null,
     *     latest_status: string|null,
     *     abandoned: bool,
     *     replacement: string|null
     * }> $packages
     * @return array{
     *     direct_update_count: int,
     *     abandoned_count: int,
     *     major_update_count: int,
     *     semver_safe_update_count: int,
     *     abandoned_packages: list<array{name: string, replacement: string|null}>,
     *     major_updates: list<array{name: string, version: string|null, latest: string|null}>
     * }
     */
    private function auditSummary(array $packages): array
    {
        $abandonedPackages = [];
        $majorUpdates = [];
        $abandonedCount = 0;
        $majorUpdateCount = 0;
        $semverSafeUpdateCount = 0;

        foreach ($packages as $package) {
            if ($package['abandoned']) {
                ++$abandonedCount;
                $abandonedPackages[] = [
                    'name' => $package['name'],
                    'replacement' => $package['replacement'],
                ];
            }

            if ($package['latest_status'] === 'update-possible') {
                ++$majorUpdateCount;
                $majorUpdates[] = [
                    'name' => $package['name'],
                    'version' => $package['version'],
                    'latest' => $package['latest'],
                ];
            } elseif ($package['latest_status'] === 'semver-safe-update') {
                ++$semverSafeUpdateCount;
            }
        }

        return [
            'direct_update_count' => count($packages),
            'abandoned_count' => $abandonedCount,
            'major_update_count' => $majorUpdateCount,
            'semver_safe_update_count' => $semverSafeUpdateCount,
            'abandoned_packages' => $abandonedPackages,
            'major_updates' => $majorUpdates,
        ];
    }

    /**
     * @param array{
     *     direct_update_count: int,
     *     abandoned_count: int,
     *     major_update_count: int,
     *     semver_safe_update_count: int,
     *     abandoned_packages: list<array{name: string, replacement: string|null}>,
     *     major_updates: list<array{name: string, version: string|null, latest: string|null}>
     * } $auditSummary
     */
    private function successMessage(array $auditSummary): string
    {
        $parts = [
            sprintf('%d direct update%s', $auditSummary['direct_update_count'], $auditSummary['direct_update_count'] === 1 ? '' : 's'),
        ];

        if ($auditSummary['abandoned_count'] > 0) {
            $parts[] = sprintf('%d abandoned', $auditSummary['abandoned_count']);
        }
        if ($auditSummary['major_update_count'] > 0) {
            $parts[] = sprintf('%d major-version', $auditSummary['major_update_count']);
        }
        if ($auditSummary['semver_safe_update_count'] > 0) {
            $parts[] = sprintf('%d semver-safe', $auditSummary['semver_safe_update_count']);
        }

        return 'Dependency audit completed: ' . implode(', ', $parts) . '.';
    }

    private function trimmedString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
