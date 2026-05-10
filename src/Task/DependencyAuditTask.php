<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class DependencyAuditTask extends AbstractProviderTask
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

        return $this->executeProvider(
            $context,
            'Summarize the dependency audit output and propose safe manual follow-up actions only. Use the learned repository patterns and recent blind-spot guidance to prioritize follow-up. Do not upgrade anything automatically.',
            [
                'working_directory' => $this->workingDirectory,
                'command' => $process->command,
                'stdout' => $process->stdout,
                'stderr' => $process->stderr,
                ...$this->sharedMetadata($context),
            ],
            'Dependency audit completed.',
        );
    }
}
