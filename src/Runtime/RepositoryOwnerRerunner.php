<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RepositoryOwnerRerunner
{
    /**
     * @param array<string, mixed> $config
     */
    public function maybeRerun(
        string $launcherPath,
        string $configFile,
        array $config,
        bool $dryRun,
        ?string $taskFilter,
        OutputInterface $output,
    ): ?int {
        if (getenv('HOUSEKEEPING_OWNER_RERUN_ACTIVE') === '1') {
            return null;
        }

        $currentUserId = $this->currentUserId();
        if ($currentUserId === null || $currentUserId !== 0) {
            return null;
        }

        $repositoryRoot = $this->repositoryRoot($config);
        if ($repositoryRoot === null) {
            return null;
        }

        $ownerUserId = $this->ownerUserId($repositoryRoot);
        if ($ownerUserId === null || $ownerUserId === $currentUserId) {
            return null;
        }

        $ownerName = $this->userNameForId($ownerUserId);
        if ($ownerName === null) {
            return null;
        }

        TimestampedConsoleOutput::write($output, sprintf('<comment>Re-running housekeeping as repository owner "%s".</comment>', $ownerName));

        return $this->rerunAsUser(
            $ownerName,
            $this->rerunCommand($launcherPath, $configFile, $dryRun, $taskFilter, $output),
            $this->currentWorkingDirectory(),
            $this->rerunTimeoutSeconds($config),
            $output,
        );
    }

    protected function currentUserId(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    protected function ownerUserId(string $path): ?int
    {
        $ownerId = @fileowner($path);

        return is_int($ownerId) ? $ownerId : null;
    }

    protected function userNameForId(int $userId): ?string
    {
        if (!function_exists('posix_getpwuid')) {
            return null;
        }

        $userInfo = posix_getpwuid($userId);
        if (!is_array($userInfo)) {
            return null;
        }

        return $userInfo['name'];
    }

    protected function currentWorkingDirectory(): ?string
    {
        $workingDirectory = getcwd();

        return is_string($workingDirectory) ? $workingDirectory : null;
    }

    /**
     * @param list<string> $command
     */
    protected function rerunAsUser(
        string $userName,
        array $command,
        ?string $workingDirectory,
        int $timeoutSeconds,
        OutputInterface $output,
    ): int
    {
        $process = new Process(
            ['su', '-l', $userName, '-s', '/bin/sh', '-c', $this->shellCommand($command, $workingDirectory)],
            null,
            null,
            null,
            $timeoutSeconds,
        );
        $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $process->getExitCode() ?? ExitCode::TASK_FAILED;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function repositoryRoot(array $config): ?string
    {
        $paths = $config['paths'] ?? null;
        $repositoryRoot = is_array($paths) ? ($paths['repository_root'] ?? null) : null;

        return is_string($repositoryRoot) && $repositoryRoot !== '' ? rtrim($repositoryRoot, '/') : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function rerunTimeoutSeconds(array $config): int
    {
        $maxRunSeconds = $config['max_run_seconds'] ?? null;

        return is_int($maxRunSeconds) && $maxRunSeconds > 0
            ? $maxRunSeconds + 120
            : 1020;
    }

    /**
     * @return list<string>
     */
    private function rerunCommand(string $launcherPath, string $configFile, bool $dryRun, ?string $taskFilter, OutputInterface $output): array
    {
        $command = [
            PHP_BINARY,
            $launcherPath,
            '--config=' . $configFile,
            'housekeeping:run',
        ];

        if ($dryRun) {
            $command[] = '--dry-run';
        }
        if ($taskFilter !== null) {
            $command[] = '--task=' . $taskFilter;
        }
        $outputFlag = $this->outputFlag($output);
        if ($outputFlag !== null) {
            $command[] = $outputFlag;
        }

        return $command;
    }

    /**
     * @param list<string> $command
     */
    private function shellCommand(array $command, ?string $workingDirectory): string
    {
        $shellCommand = 'exec env HOUSEKEEPING_OWNER_RERUN_ACTIVE=1 ' . implode(' ', array_map(
            static fn (string $item): string => escapeshellarg($item),
            $command,
        ));

        if ($workingDirectory === null) {
            return $shellCommand;
        }

        return 'cd ' . escapeshellarg($workingDirectory) . ' && ' . $shellCommand;
    }

    private function outputFlag(?OutputInterface $output): ?string
    {
        if ($output === null) {
            return null;
        }
        if ($output->isQuiet()) {
            return '--quiet';
        }
        if ($output->isDebug()) {
            return '-vvv';
        }
        if ($output->isVeryVerbose()) {
            return '-vv';
        }

        return $output->isVerbose() ? '--verbose' : null;
    }
}
