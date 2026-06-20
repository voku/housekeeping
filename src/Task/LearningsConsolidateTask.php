<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

/**
 * Runs the repository's deterministic learning-consolidation step on a schedule.
 *
 * This is the automated "sleep cycle": it executes a configured command that
 * turns recall usage and validated findings into *reviewable candidate
 * proposals* (for example `agent-loop learn guidance-evaluate --write-candidates`).
 * It deliberately never approves, applies, or activates durable guidance — a
 * maintainer still reviews every candidate. The command itself owns that safe
 * posture; this task only schedules it and reports the outcome.
 */
final readonly class LearningsConsolidateTask extends AbstractIntervalTask
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        int $intervalSeconds,
        private ProcessExecutor $processExecutor,
        private string $workingDirectory,
        private array $command,
        private int $timeoutSeconds,
    ) {
        parent::__construct($intervalSeconds);
    }

    public function name(): string
    {
        return 'learnings:consolidate';
    }

    public function run(RunContext $context): TaskResult
    {
        if ($this->command === []) {
            return TaskResult::skipped('Learning consolidation skipped: no consolidation command was configured.');
        }
        if ($context->dryRun) {
            return TaskResult::skipped('Dry-run: learnings:consolidate command was not executed.');
        }

        $process = $this->processExecutor->execute($this->command, $this->workingDirectory, $this->timeoutSeconds);

        if ($process->timedOut) {
            return TaskResult::failure('Learning consolidation command timed out.', [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
            ]);
        }

        if (!$process->successful()) {
            $failureContext = [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
                'exit_code' => $process->exitCode,
                'stdout' => $process->stdout,
                'stderr' => $process->stderr,
            ];
            if ($process->exceptionMessage !== null) {
                $failureContext['exception'] = $process->exceptionMessage;
            }

            return TaskResult::failure('Learning consolidation command failed.', $failureContext);
        }

        $context->setMetadataValue('consolidation.last_ran_at', time());
        $output = trim($process->stdout);
        if ($output !== '') {
            $context->setMetadataValue('consolidation.last_output', $output);
        }

        return TaskResult::success(
            'Learning consolidation completed (reviewable candidate proposals only; no approvals).',
            [
                'command' => $process->command,
                'working_directory' => $process->workingDirectory,
                'stdout' => $process->stdout,
            ],
        );
    }
}
