<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Command\HousekeepingNextCommand;
use HousekeepingAgentCron\Runtime\ExitCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class HousekeepingNextCommandTest extends TestCase
{
    public function testNextCommandShowsDueAndUpcomingTasks(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-next-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $stateFile = $dir . '/state/state.json';
        (new Filesystem())->mkdir(dirname($stateFile));
        file_put_contents($stateFile, json_encode([
            'tasks' => [
                'project:discover' => [
                    'last_finished_at' => time(),
                ],
            ],
            'providers' => [],
            'runs' => [],
        ], JSON_PRETTY_PRINT) . PHP_EOL);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $stateFile,
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
                'repository_root' => dirname(__DIR__),
            ],
            'tasks' => [
                'project:discover' => [
                    'enabled' => true,
                    'interval_seconds' => 3600,
                    'priority' => 200,
                ],
                'docs:refresh' => [
                    'enabled' => true,
                    'interval_seconds' => 86400,
                    'priority' => 100,
                    'provider' => 'local-null-provider',
                    'input_files' => [],
                ],
            ],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingNextCommand($configFile));
            $exitCode = $tester->execute([]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('project:discover', $display);
            self::assertStringContainsString('docs:refresh', $display);
            self::assertStringContainsString('due now', $display);
            self::assertMatchesRegularExpression('/Next due task: docs:refresh/', $display);
            self::assertMatchesRegularExpression('/in [1-9][0-9]* s/', $display);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testNextCommandCanRenderJson(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-next-json-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $stateFile = $dir . '/state/state.json';
        (new Filesystem())->mkdir(dirname($stateFile));
        file_put_contents($stateFile, json_encode([
            'schema_version' => 1,
            'tasks' => [],
            'providers' => [],
            'runs' => [],
        ], JSON_PRETTY_PRINT) . PHP_EOL);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $stateFile,
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
                'repository_root' => dirname(__DIR__),
            ],
            'tasks' => [
                'project:discover' => [
                    'enabled' => true,
                    'interval_seconds' => 3600,
                    'priority' => 200,
                ],
            ],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingNextCommand($configFile));
            $exitCode = $tester->execute(['--json' => true]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            $decoded = json_decode($tester->getDisplay(), true);
            self::assertIsArray($decoded);
            self::assertSame('project:discover', $decoded['recommended_task'] ?? null);
            self::assertSame('due_now', $decoded['recommended_reason'] ?? null);
            $tasks = $decoded['tasks'] ?? null;
            self::assertIsArray($tasks);
            self::assertIsArray($tasks[0] ?? null);
            self::assertTrue($tasks[0]['due'] ?? false);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
