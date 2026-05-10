<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Command\HousekeepingRunCommand;
use HousekeepingAgentCron\Runtime\ExitCode;
use PHPUnit\Framework\TestCase;
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
        } finally {
            $lock->release();
            (new Filesystem())->remove($dir);
        }
    }
}
