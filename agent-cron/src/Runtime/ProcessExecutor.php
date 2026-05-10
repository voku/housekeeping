<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ProcessExecutor
{
    /**
     * @param list<string> $command
     */
    public function execute(array $command, string $workingDirectory, int $timeoutSeconds = 60, ?string $input = null): ProcessResult
    {
        $process = new Process($command, $workingDirectory, null, $input, $timeoutSeconds > 0 ? $timeoutSeconds : 60);

        try {
            $process->run();

            return new ProcessResult(
                $command,
                $process->getExitCode() ?? 1,
                $process->getOutput(),
                $process->getErrorOutput(),
                false,
            );
        } catch (ProcessTimedOutException) {
            return new ProcessResult(
                $command,
                $process->getExitCode() ?? 1,
                $process->getOutput(),
                $process->getErrorOutput(),
                true,
            );
        }
    }
}
