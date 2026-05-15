<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Command\HousekeepingListCommand;
use HousekeepingAgentCron\Runtime\ExitCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

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
        self::assertArrayHasKey('interval_seconds', $tasks[0]);
        self::assertArrayHasKey('priority', $tasks[0]);
    }
}
