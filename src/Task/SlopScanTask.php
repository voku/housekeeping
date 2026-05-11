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

        $summary = $report['summary'] ?? null;
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
                'report' => $report,
                ...$this->sharedMetadata($context),
            ],
            'slop-scan suggestions prepared.',
        );
    }
}
