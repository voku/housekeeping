<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class SlopScanTask extends AbstractProviderTask
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
        return 'slop:scan';
    }

    public function run(RunContext $context): TaskResult
    {
        if ($context->dryRun) {
            return TaskResult::skipped('Dry-run: slop:scan was not sent to a provider.');
        }

        $process = $this->processExecutor->execute($this->command, $this->workingDirectory, $this->timeoutSeconds);
        if ($process->timedOut) {
            return TaskResult::failure('slop-scan command timed out.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
            ]);
        }
        if ($process->exceptionMessage !== null) {
            return TaskResult::failure('slop-scan command failed.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
                'exit_code' => $process->exitCode,
                'stderr' => $process->stderr,
                'stdout' => $process->stdout,
                'exception' => $process->exceptionMessage,
            ]);
        }
        if (
            !$process->successful()
            && str_contains($process->stderr, 'require a PHP version ">= 8.4.0"')
        ) {
            return TaskResult::skipped('slop-scan PHAR requires PHP 8.4+; the configured runtime is incompatible.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
                'exit_code' => $process->exitCode,
                'stderr' => $process->stderr,
            ]);
        }
        if ($process->stdout === '') {
            if (!$process->successful()) {
                return TaskResult::failure('slop-scan command failed.', [
                    'command' => $process->command,
                    'working_directory' => $process->workingDirectory,
                    'exit_code' => $process->exitCode,
                    'stderr' => $process->stderr,
                    'stdout' => $process->stdout,
                ]);
            }

            return TaskResult::skipped('No slop-scan output was produced.');
        }
        if ($this->looksLikePhpMemoryExhaustion($process->stdout, $process->stderr)) {
            return TaskResult::failure('slop-scan exhausted PHP memory; raise the configured memory_limit.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
                'exit_code' => $process->exitCode,
                'stderr' => $process->stderr,
                'stdout' => $process->stdout,
            ]);
        }

        $report = json_decode($process->stdout, true);
        if (!is_array($report)) {
            return TaskResult::failure('slop-scan output was not valid JSON.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
                'exit_code' => $process->exitCode,
                'stderr' => $process->stderr,
                'stdout' => $process->stdout,
            ]);
        }
        /** @var array<string, mixed> $typedReport */
        $typedReport = $report;

        $summary = $typedReport['summary'] ?? null;
        $findingCount = is_array($summary) ? ($summary['findingCount'] ?? null) : null;
        if (!is_int($findingCount)) {
            return TaskResult::failure('slop-scan JSON report is missing summary.findingCount.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
                'exit_code' => $process->exitCode,
                'stderr' => $process->stderr,
                'stdout' => $process->stdout,
            ]);
        }
        if ($findingCount === 0) {
            return TaskResult::skipped('No slop findings detected.');
        }

        return $this->executeProvider(
            $context,
            'Review the slop-scan report and produce safe cleanup suggestions only. Use the learned repository patterns and recent blind-spot guidance to prioritize follow-up. Do not apply changes automatically or suppress findings without justification.',
            [
                'working_directory' => $this->workingDirectory,
                'command' => $process->command,
                'exit_code' => $process->exitCode,
                'report' => $this->providerReport($typedReport),
                ...$this->sharedMetadata($context),
            ],
            'slop-scan suggestions prepared.',
        );
    }

    private function looksLikePhpMemoryExhaustion(string $stdout, string $stderr): bool
    {
        $combinedOutput = $stdout . "\n" . $stderr;

        return str_contains($combinedOutput, 'Allowed memory size')
            || str_contains($combinedOutput, 'Out of memory');
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function providerReport(array $report): array
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $findings = is_array($report['findings'] ?? null) ? array_slice($report['findings'], 0, 50) : [];

        return [
            'summary' => $summary,
            'findings' => $findings,
            'finding_count' => is_int($summary['findingCount'] ?? null) ? $summary['findingCount'] : count($findings),
            'findings_truncated' => is_array($report['findings'] ?? null) && count($report['findings']) > count($findings),
        ];
    }
}
