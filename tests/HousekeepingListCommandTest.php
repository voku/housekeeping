<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Command\HousekeepingListCommand;
use HousekeepingAgentCron\Runtime\ExitCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class HousekeepingListCommandTest extends TestCase
{
    public function testListDisplaysConfiguredTasks(): void
    {
        $tester = new CommandTester(new HousekeepingListCommand(__DIR__ . '/../config/tasks.php'));

        $exitCode = $tester->execute([]);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertStringContainsString('Task', $tester->getDisplay());
        self::assertStringContainsString('Provider', $tester->getDisplay());
        self::assertStringContainsString('project:discover', $tester->getDisplay());
        self::assertStringContainsString('commits:learn', $tester->getDisplay());
        self::assertStringContainsString('docs:refresh', $tester->getDisplay());
        self::assertStringContainsString('todo:refine', $tester->getDisplay());
        self::assertStringContainsString('self-improve:housekeeping', $tester->getDisplay());
        self::assertStringContainsString('deps:audit', $tester->getDisplay());
        self::assertStringContainsString('phpstan:suggest-fixes', $tester->getDisplay());
        self::assertStringContainsString('slop:scan', $tester->getDisplay());
    }

    public function testListCanRenderJson(): void
    {
        $tester = new CommandTester(new HousekeepingListCommand(__DIR__ . '/../config/tasks.php'));

        $exitCode = $tester->execute(['--json' => true]);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        $tasks = $decoded['tasks'] ?? null;
        self::assertIsArray($tasks);
        self::assertIsArray($tasks[0] ?? null);
        self::assertSame('project:discover', $tasks[0]['name'] ?? null);
        self::assertSame(21600, $tasks[0]['interval_seconds'] ?? null);
        self::assertSame(300, $tasks[0]['priority'] ?? null);
    }

    public function testListJsonFallsBackToDefaultIntervalAndPriorityWhenConfigValuesAreMissing(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-list-defaults-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        (new Filesystem())->mkdir($dir);
        file_put_contents($configFile, '<?php return ' . var_export([
            'tasks' => [
                'project:discover' => [
                    'enabled' => true,
                ],
            ],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingListCommand($configFile));
            $exitCode = $tester->execute(['--json' => true]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            $decoded = json_decode($tester->getDisplay(), true);
            self::assertIsArray($decoded);
            $tasks = $decoded['tasks'] ?? null;
            self::assertIsArray($tasks);
            self::assertIsArray($tasks[0] ?? null);
            self::assertSame('project:discover', $tasks[0]['name'] ?? null);
            self::assertSame(3600, $tasks[0]['interval_seconds'] ?? null);
            self::assertSame(0, $tasks[0]['priority'] ?? null);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testListJsonFallsBackWhenIntervalIsZeroAndPriorityIsNotAnInteger(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-list-invalid-values-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        (new Filesystem())->mkdir($dir);
        file_put_contents($configFile, '<?php return ' . var_export([
            'tasks' => [
                'project:discover' => [
                    'enabled' => true,
                    'interval_seconds' => 0,
                    'priority' => 'high',
                ],
            ],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingListCommand($configFile));
            $exitCode = $tester->execute(['--json' => true]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            $decoded = json_decode($tester->getDisplay(), true);
            self::assertIsArray($decoded);
            $tasks = $decoded['tasks'] ?? null;
            self::assertIsArray($tasks);
            self::assertIsArray($tasks[0] ?? null);
            self::assertSame('project:discover', $tasks[0]['name'] ?? null);
            self::assertSame(3600, $tasks[0]['interval_seconds'] ?? null);
            self::assertSame(0, $tasks[0]['priority'] ?? null);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
