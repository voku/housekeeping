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
        self::assertStringContainsString('docs:refresh', $tester->getDisplay());
        self::assertStringContainsString('todo:refine', $tester->getDisplay());
        self::assertStringContainsString('deps:audit', $tester->getDisplay());
        self::assertStringContainsString('phpstan:suggest-fixes', $tester->getDisplay());
    }
}
