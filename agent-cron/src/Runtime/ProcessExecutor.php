<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

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
                $this->stdout($process),
                $this->stderr($process),
                false,
                $workingDirectory,
            );
        } catch (ProcessTimedOutException) {
            return new ProcessResult(
                $command,
                $process->getExitCode() ?? 1,
                $this->stdout($process),
                $this->stderr($process),
                true,
                $workingDirectory,
            );
        } catch (Throwable $throwable) {
            $stderr = $this->stderr($process);

            return new ProcessResult(
                $command,
                $process->getExitCode() ?? 1,
                $this->stdout($process),
                $stderr !== '' ? $stderr . PHP_EOL . $throwable->getMessage() : $throwable->getMessage(),
                false,
                $workingDirectory,
                $throwable->getMessage(),
            );
        }
    }

    private function stdout(Process $process): string
    {
        return $process->isStarted() ? $process->getOutput() : '';
    }

    private function stderr(Process $process): string
    {
        return $process->isStarted() ? $process->getErrorOutput() : '';
    }
}
