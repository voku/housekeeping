<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class PhpstanFixSuggestionTask extends AbstractProviderTask
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        int $intervalSeconds,
        string $providerName,
        private ProcessExecutor $processExecutor,
        private string $workingDirectory,
        private array $command,
        private int $timeoutSeconds,
    ) {
        parent::__construct($intervalSeconds, $providerName);
    }

    public function name(): string
    {
        return 'phpstan:suggest-fixes';
    }

    public function run(RunContext $context): TaskResult
    {
        if ($context->dryRun) {
            return TaskResult::skipped('Dry-run: phpstan:suggest-fixes was not sent to a provider.');
        }

        $process = $this->processExecutor->execute($this->command, $this->workingDirectory, $this->timeoutSeconds);
        if ($process->timedOut) {
            return TaskResult::failure('PHPStan command timed out.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
            ]);
        }
        if ($process->exceptionMessage !== null) {
            return TaskResult::failure('PHPStan command failed.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
                'exit_code' => $process->exitCode,
                'stderr' => $process->stderr,
                'stdout' => $process->stdout,
                'exception' => $process->exceptionMessage,
            ]);
        }

        $analysisOutput = $process->combinedOutput();
        if ($analysisOutput === '') {
            if (!$process->successful()) {
                return TaskResult::failure('PHPStan command failed.', [
                    'command' => $process->command,
                    'working_directory' => $process->workingDirectory,
                    'exit_code' => $process->exitCode,
                    'stderr' => $process->stderr,
                    'stdout' => $process->stdout,
                ]);
            }

            return TaskResult::skipped('No PHPStan output was produced.');
        }
        if ($process->successful()) {
            return TaskResult::skipped('No PHPStan issues detected.');
        }

        return $this->executeProvider(
            $context,
            'Review the PHPStan analysis output and produce safe fix suggestions only. Use the learned repository patterns and recent blind-spot guidance to prioritize follow-up. Do not apply changes or invent missing diagnostics.',
            [
                'working_directory' => $this->workingDirectory,
                'command' => $process->command,
                'exit_code' => $process->exitCode,
                'analysis_output' => $analysisOutput,
                ...$this->sharedMetadata($context),
            ],
            'PHPStan suggestions prepared.',
        );
    }
}
