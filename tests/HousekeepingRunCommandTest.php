<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Command\HousekeepingRunCommand;
use HousekeepingAgentCron\Runtime\ExitCode;
use HousekeepingAgentCron\Runtime\RepositoryOwnerRerunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class HousekeepingRunCommandTest extends TestCase
{
    public function testLockPreventsConcurrentRuns(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-command-' . bin2hex(random_bytes(4));
        $lockDir = $dir . '/lock';
        (new Filesystem())->mkdir($lockDir);
        $configFile = $dir . '/tasks.php';
        file_put_contents($configFile, '<?php return ' . var_export([
            'max_run_seconds' => 900,
            'max_tasks_per_run' => 3,
            'paths' => [
                'logs' => $dir . '/logs',
                'state' => $dir . '/state/state.json',
                'lock' => $lockDir,
            ],
            'tasks' => [
                'docs:refresh' => [
                    'enabled' => true,
                    'interval_seconds' => 3600,
                    'provider' => 'local-null-provider',
                ],
            ],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                    'daily_budget' => 24,
                    'cooldown_seconds' => 0,
                ],
            ],
        ], true) . ';');

        $lock = (new LockFactory(new FlockStore($lockDir)))->createLock('housekeeping-run', 1.0, false);
        self::assertTrue($lock->acquire());

        try {
            $tester = new CommandTester(new HousekeepingRunCommand($configFile));
            $exitCode = $tester->execute([]);
            self::assertSame(ExitCode::LOCK_HELD, $exitCode);
            self::assertMatchesRegularExpression('/^\[[^\]]+\] Another housekeeping run is already active\./m', $tester->getDisplay());
        } finally {
            $lock->release();
            (new Filesystem())->remove($dir);
        }
    }

    public function testUnknownTaskOptionReturnsInvalidConfig(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-command-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        (new Filesystem())->mkdir($dir);
        file_put_contents($configFile, '<?php return ' . var_export([
            'max_run_seconds' => 900,
            'max_tasks_per_run' => 3,
            'paths' => [
                'logs' => $dir . '/logs',
                'state' => $dir . '/state/state.json',
                'lock' => $dir . '/lock',
            ],
            'tasks' => [
                'docs:refresh' => [
                    'enabled' => true,
                    'interval_seconds' => 3600,
                    'provider' => 'local-null-provider',
                ],
            ],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                    'daily_budget' => 24,
                    'cooldown_seconds' => 0,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingRunCommand($configFile));
            $exitCode = $tester->execute(['--task' => 'unknown']);

            self::assertSame(ExitCode::INVALID_CONFIG, $exitCode);
            self::assertStringContainsString('Unknown task configured for --task: unknown', $tester->getDisplay());
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testRepositoryOwnerRerunShortCircuitsMainExecution(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-command-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        (new Filesystem())->mkdir($dir);
        file_put_contents($configFile, '<?php return ' . var_export([
            'max_run_seconds' => 900,
            'max_tasks_per_run' => 3,
            'paths' => [
                'logs' => $dir . '/logs',
                'state' => $dir . '/state/state.json',
                'lock' => $dir . '/lock',
                'repository_root' => $dir . '/repo',
            ],
            'tasks' => [
                'docs:refresh' => [
                    'enabled' => true,
                    'interval_seconds' => 3600,
                    'provider' => 'local-null-provider',
                ],
            ],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                    'daily_budget' => 24,
                    'cooldown_seconds' => 0,
                ],
            ],
        ], true) . ';');

        $rerunner = new class extends RepositoryOwnerRerunner {
            public bool $called = false;

            /**
             * @param array<string, mixed> $config
             */
            public function maybeRerun(
                string $launcherPath,
                string $configFile,
                array $config,
                bool $dryRun,
                ?string $taskFilter,
                \Symfony\Component\Console\Output\OutputInterface $output,
            ): int {
                $this->called = true;
                $output->writeln('<comment>rerun happened</comment>');

                return ExitCode::SUCCESS;
            }
        };

        try {
            $tester = new CommandTester(new HousekeepingRunCommand($configFile, repositoryOwnerRerunner: $rerunner));
            $exitCode = $tester->execute([]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            self::assertTrue($rerunner->called);
            self::assertStringContainsString('rerun happened', $tester->getDisplay());
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testVerboseRunOutputsTaskProgress(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-command-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        (new Filesystem())->mkdir($dir . '/repo');
        file_put_contents($dir . '/repo/README.md', "# Docs\n");
        file_put_contents($configFile, '<?php return ' . var_export([
            'max_run_seconds' => 900,
            'max_tasks_per_run' => 3,
            'paths' => [
                'logs' => $dir . '/logs',
                'state' => $dir . '/state/state.json',
                'lock' => $dir . '/lock',
                'repository_root' => $dir . '/repo',
            ],
            'tasks' => [
                'docs:refresh' => [
                    'enabled' => true,
                    'interval_seconds' => 3600,
                    'provider' => 'local-null-provider',
                    'input_files' => [$dir . '/repo/README.md'],
                ],
            ],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                    'daily_budget' => 24,
                    'cooldown_seconds' => 0,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingRunCommand($configFile));
            $exitCode = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            self::assertMatchesRegularExpression('/^\[[^\]]+\] \[run\] config=' . preg_quote($configFile, '/') . '/m', $tester->getDisplay());
            self::assertMatchesRegularExpression('/^\[[^\]]+\] \[run\] docs:refresh/m', $tester->getDisplay());
            self::assertMatchesRegularExpression('/^\[[^\]]+\] \[ok\] docs:refresh: Documentation refresh completed\./m', $tester->getDisplay());
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
