<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\LearningsConsolidateTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LearningsConsolidateTaskTest extends TestCase
{
    /**
     * @param list<string> $command
     */
    private function runTask(array $command, bool $dryRun, string $workingDirectory): RunContext
    {
        return new RunContext(
            $dryRun,
            null,
            time(),
            ['providers' => []],
            ['tasks' => [], 'providers' => [], 'runs' => []],
            [],
            new InMemoryStateStore(),
            new JsonLogger($workingDirectory . '/logs/housekeeping.log'),
            [],
        );
    }

    public function testRunsConsolidationCommandAndSucceeds(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-consolidate-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);

        $task = new LearningsConsolidateTask(
            3600,
            new ProcessExecutor(),
            $workingDirectory,
            ['php', '-r', 'fwrite(STDOUT, "Wrote 2 candidate proposals.");'],
            30,
        );

        try {
            $result = $task->run($this->runTask([], false, $workingDirectory));

            self::assertTrue($result->successful);
            self::assertFalse($result->skipped);
            self::assertStringContainsString('candidate proposals only', $result->message);
            self::assertSame('Wrote 2 candidate proposals.', $result->context['stdout'] ?? null);
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }

    public function testDryRunDoesNotExecute(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-consolidate-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);

        $task = new LearningsConsolidateTask(
            3600,
            new ProcessExecutor(),
            $workingDirectory,
            ['php', '-r', 'fwrite(STDOUT, "should not run");'],
            30,
        );

        try {
            $result = $task->run($this->runTask([], true, $workingDirectory));

            self::assertTrue($result->skipped);
            self::assertStringContainsString('Dry-run', $result->message);
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }

    public function testEmptyCommandSkips(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-consolidate-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);

        $task = new LearningsConsolidateTask(3600, new ProcessExecutor(), $workingDirectory, [], 30);

        try {
            $result = $task->run($this->runTask([], false, $workingDirectory));

            self::assertTrue($result->skipped);
            self::assertStringContainsString('no consolidation command', $result->message);
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }

    public function testFailingCommandIsReportedAsFailure(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-consolidate-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);

        $task = new LearningsConsolidateTask(
            3600,
            new ProcessExecutor(),
            $workingDirectory,
            ['php', '-r', 'fwrite(STDERR, "boom"); exit(1);'],
            30,
        );

        try {
            $result = $task->run($this->runTask([], false, $workingDirectory));

            self::assertFalse($result->successful);
            self::assertFalse($result->skipped);
            self::assertStringContainsString('Learning consolidation command failed', $result->message);
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }
}
