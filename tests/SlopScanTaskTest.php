<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\SlopScanTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SlopScanTaskTest extends TestCase
{
    public function testSlopFindingsAreForwardedToProvider(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-slop-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);

        $provider = new class implements ProviderAdapter {
            public int $calls = 0;

            /** @var array<string, mixed>|null */
            public ?array $payload = null;

            public function name(): string
            {
                return 'local-null-provider';
            }

            public function isAvailable(RunContext $context): bool
            {
                return true;
            }

            public function execute(ProviderRequest $request): ProviderResult
            {
                ++$this->calls;
                $this->payload = $request->payload;

                return ProviderResult::success('Accepted.');
            }
        };

        $report = json_encode([
            'summary' => [
                'findingCount' => 1,
            ],
            'findings' => [
                ['ruleId' => 'php.debug-output', 'message' => 'Found debug output.'],
            ],
        ], JSON_UNESCAPED_SLASHES);
        self::assertNotFalse($report);

        $task = new SlopScanTask(
            3600,
            'local-null-provider',
            new ProcessExecutor(),
            $workingDirectory,
            ['php', '-r', 'fwrite(STDOUT, ' . var_export($report, true) . '); exit(1);'],
            30,
        );

        try {
            $result = $task->run(new RunContext(
                false,
                null,
                time(),
                [
                    'providers' => [
                        'local-null-provider' => [
                            'enabled' => true,
                            'daily_budget' => 1,
                            'cooldown_seconds' => 0,
                        ],
                    ],
                ],
                ['tasks' => [], 'providers' => [], 'runs' => []],
                new InMemoryStateStore(),
                new JsonLogger($workingDirectory . '/logs/housekeeping.log'),
                ['local-null-provider' => $provider],
            ));

            self::assertTrue($result->successful);
            self::assertFalse($result->skipped);
            self::assertSame('slop-scan suggestions prepared.', $result->message);
            self::assertSame(1, $provider->calls);
            self::assertIsArray($provider->payload);
            self::assertIsArray($provider->payload['report'] ?? null);
            self::assertIsArray($provider->payload['report']['summary'] ?? null);
            self::assertSame(1, $provider->payload['report']['summary']['findingCount'] ?? null);
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }

    public function testSuccessfulScanWithoutFindingsIsSkipped(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-slop-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);

        $report = json_encode([
            'summary' => [
                'findingCount' => 0,
            ],
            'findings' => [],
        ], JSON_UNESCAPED_SLASHES);
        self::assertNotFalse($report);

        $task = new SlopScanTask(
            3600,
            'local-null-provider',
            new ProcessExecutor(),
            $workingDirectory,
            ['php', '-r', 'fwrite(STDOUT, ' . var_export($report, true) . ');'],
            30,
        );

        try {
            $result = $task->run(new RunContext(
                false,
                null,
                time(),
                ['providers' => []],
                ['tasks' => [], 'providers' => [], 'runs' => []],
                new InMemoryStateStore(),
                new JsonLogger($workingDirectory . '/logs/housekeeping.log'),
                [],
            ));

            self::assertTrue($result->successful);
            self::assertTrue($result->skipped);
            self::assertSame('No slop findings detected.', $result->message);
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }

    public function testPhpRuntimeMismatchIsSkippedWithActionableMessage(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-slop-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);

        $task = new SlopScanTask(
            3600,
            'local-null-provider',
            new ProcessExecutor(),
            $workingDirectory,
            ['php', '-r', 'fwrite(STDERR, \'Your Composer dependencies require a PHP version ">= 8.4.0".\'); exit(255);'],
            30,
        );

        try {
            $result = $task->run(new RunContext(
                false,
                null,
                time(),
                ['providers' => []],
                ['tasks' => [], 'providers' => [], 'runs' => []],
                new InMemoryStateStore(),
                new JsonLogger($workingDirectory . '/logs/housekeeping.log'),
                [],
            ));

            self::assertTrue($result->successful);
            self::assertTrue($result->skipped);
            self::assertSame('slop-scan PHAR requires PHP 8.4+; the configured runtime is incompatible.', $result->message);
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }
}
