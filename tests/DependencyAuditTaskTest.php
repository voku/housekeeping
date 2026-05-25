<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\DependencyAuditTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DependencyAuditTaskTest extends TestCase
{
    public function testDependencyAuditForwardsStructuredSummaryToProvider(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-deps-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);
        file_put_contents($workingDirectory . '/composer.json', "{}\n");

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
            'installed' => [
                [
                    'name' => 'vendor/major',
                    'version' => '1.2.3',
                    'latest' => '2.0.0',
                    'latest-status' => 'update-possible',
                    'abandoned' => false,
                ],
                [
                    'name' => 'vendor/abandoned',
                    'version' => '3.4.5',
                    'latest' => '3.5.0',
                    'latest-status' => 'semver-safe-update',
                    'abandoned' => 'vendor/replacement',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);
        self::assertNotFalse($report);

        $task = new DependencyAuditTask(
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
                [],
                new InMemoryStateStore(),
                new JsonLogger($workingDirectory . '/logs/housekeeping.log'),
                ['local-null-provider' => $provider],
            ));

            self::assertTrue($result->successful);
            self::assertFalse($result->skipped);
            self::assertSame('Dependency audit completed: 2 direct updates, 1 abandoned, 1 major-version, 1 semver-safe.', $result->message);
            self::assertSame(1, $provider->calls);
            self::assertIsArray($provider->payload);
            self::assertSame($workingDirectory, $provider->payload['working_directory'] ?? null);
            self::assertIsArray($provider->payload['audit_summary'] ?? null);
            self::assertSame(2, $provider->payload['audit_summary']['direct_update_count'] ?? null);
            self::assertSame(1, $provider->payload['audit_summary']['abandoned_count'] ?? null);
            self::assertSame(1, $provider->payload['audit_summary']['major_update_count'] ?? null);
            self::assertSame(1, $provider->payload['audit_summary']['semver_safe_update_count'] ?? null);
            self::assertSame(
                [['name' => 'vendor/abandoned', 'replacement' => 'vendor/replacement']],
                $provider->payload['audit_summary']['abandoned_packages'] ?? null,
            );
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }

    public function testDependencyAuditSkipsWhenNoDirectUpdatesAreReported(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-deps-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);
        file_put_contents($workingDirectory . '/composer.json', "{}\n");

        $report = json_encode(['installed' => []], JSON_UNESCAPED_SLASHES);
        self::assertNotFalse($report);

        $task = new DependencyAuditTask(
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
                [],
                new InMemoryStateStore(),
                new JsonLogger($workingDirectory . '/logs/housekeeping.log'),
                [],
            ));

            self::assertTrue($result->successful);
            self::assertTrue($result->skipped);
            self::assertSame('No direct dependency updates detected.', $result->message);
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }

    public function testDependencyAuditFailsWhenOutputIsNotJson(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-deps-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);
        file_put_contents($workingDirectory . '/composer.json', "{}\n");

        $task = new DependencyAuditTask(
            3600,
            'local-null-provider',
            new ProcessExecutor(),
            $workingDirectory,
            ['php', '-r', 'fwrite(STDOUT, "not-json");'],
            30,
        );

        try {
            $result = $task->run(new RunContext(
                false,
                null,
                time(),
                ['providers' => []],
                ['tasks' => [], 'providers' => [], 'runs' => []],
                [],
                new InMemoryStateStore(),
                new JsonLogger($workingDirectory . '/logs/housekeeping.log'),
                [],
            ));

            self::assertFalse($result->successful);
            self::assertFalse($result->skipped);
            self::assertSame('Dependency audit output was not valid JSON.', $result->message);
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }
}
