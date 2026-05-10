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
        if (!is_file($this->workingDirectory . '/vendor/bin/phpstan')) {
            return TaskResult::skipped('PHPStan suggestions skipped because PHPStan is not installed in the working directory.');
        }
        if ($context->dryRun) {
            return TaskResult::skipped('Dry-run: phpstan:suggest-fixes was not sent to a provider.');
        }

        $process = $this->processExecutor->execute($this->command, $this->workingDirectory, $this->timeoutSeconds);
        if ($process->timedOut) {
            return TaskResult::failure('PHPStan command timed out.', ['command' => $process->command]);
        }

        $analysisOutput = $process->combinedOutput();
        if ($analysisOutput === '') {
            return TaskResult::skipped('No PHPStan output was produced.');
        }
        if ($process->successful()) {
            return TaskResult::skipped('No PHPStan issues detected.');
        }

        return $this->executeProvider(
            $context,
            'Review the PHPStan analysis output and produce safe fix suggestions only. Do not apply changes or invent missing diagnostics.',
            [
                'working_directory' => $this->workingDirectory,
                'command' => $process->command,
                'exit_code' => $process->exitCode,
                'analysis_output' => $analysisOutput,
            ],
            'PHPStan suggestions prepared.',
        );
    }
}
