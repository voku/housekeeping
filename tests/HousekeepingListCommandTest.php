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
}
