<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\ProcessExecutor;
use PHPUnit\Framework\TestCase;

final class ProcessExecutorTest extends TestCase
{
    public function testExecuteReturnsStructuredFailureWhenProcessCannotStart(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-missing-dir-' . bin2hex(random_bytes(4));

        $result = (new ProcessExecutor())->execute(['php', '-v'], $workingDirectory);

        self::assertFalse($result->successful());
        self::assertFalse($result->timedOut);
        self::assertSame($workingDirectory, $result->workingDirectory);
        self::assertNotNull($result->exceptionMessage);
        self::assertStringContainsString($workingDirectory, $result->exceptionMessage);
    }
}
