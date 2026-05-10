<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\ProviderCapacityInspector;
use PHPUnit\Framework\TestCase;

final class ProviderCapacityInspectorTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    public function testInspectorSortsProvidersDeterministically(): void
    {
        $inspector = new ProviderCapacityInspector();

        $reports = $inspector->inspect([
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
                'codex' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo json_encode(["session" => ["remaining_percent" => 80, "reset_in_seconds" => 3600], "week" => ["remaining_percent" => 50, "reset_in_seconds" => 7200]]);'],
                ],
                'gemini' => [
                    'enabled' => true,
                    'daily_budget' => 20,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo json_encode(["free" => ["remaining_ratio" => 0.9, "reset_in_seconds" => 1800]]);'],
                ],
                'copilot' => [
                    'enabled' => false,
                    'daily_budget' => 5,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo json_encode(["free" => ["remaining_ratio" => 0.7, "reset_in_seconds" => 900]]);'],
                ],
            ],
        ], [
            'providers' => [
                'codex' => [
                    'usage' => [gmdate('Y-m-d') => 2],
                ],
            ],
        ]);

        self::assertSame(['gemini', 'codex', 'copilot'], array_map(static fn ($report): string => $report->provider, $reports));
        self::assertSame('ready', $reports[0]->status);
        self::assertSame(0.9, $reports[0]->externalRemainingRatio);
        self::assertSame(8, $reports[1]->internalBudgetRemaining);
        self::assertSame('disabled', $reports[2]->status);
    }

    public function testInspectorParsesPercentageTextProbeOutput(): void
    {
        $inspector = new ProviderCapacityInspector();

        $reports = $inspector->inspect([
            'providers' => [
                'gemini' => [
                    'enabled' => true,
                    'daily_budget' => 20,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo "gemini-2.5-pro 3.5% used resets 19h38m\n";'],
                ],
            ],
        ], []);

        self::assertCount(1, $reports);
        self::assertSame('ready', $reports[0]->status);
        self::assertNotNull($reports[0]->externalRemainingRatio);
        self::assertEqualsWithDelta(0.965, $reports[0]->externalRemainingRatio, 0.0001);
        self::assertNotNull($reports[0]->externalResetAt);
    }

    public function testDisabledProviderSkipsProbeExecution(): void
    {
        $inspector = new ProviderCapacityInspector();

        $reports = $inspector->inspect([
            'providers' => [
                'copilot' => [
                    'enabled' => false,
                    'daily_budget' => 5,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'fwrite(STDERR, "should-not-run"); exit(1);'],
                ],
            ],
        ], []);

        self::assertCount(1, $reports);
        self::assertSame('disabled', $reports[0]->status);
        self::assertSame('Probe skipped because provider is disabled.', $reports[0]->probeMessage);
        self::assertNull($reports[0]->externalRemainingRatio);
    }

    public function testRelativeResetTimestampsUseSingleCapturedNowForStableOrdering(): void
    {
        $inspector = new ProviderCapacityInspector();

        $reports = $inspector->inspect([
            'providers' => [
                'alpha' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'sleep(2); echo "alpha 50% left resets 1h\n";'],
                ],
                'beta' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo "beta 50% left resets 1h\n";'],
                ],
            ],
        ], []);

        self::assertSame(['alpha', 'beta'], array_map(static fn ($report): string => $report->provider, $reports));
        self::assertSame($reports[0]->externalResetAt, $reports[1]->externalResetAt);
    }

    public function testLatestResetIsReportedAcrossMultipleMetrics(): void
    {
        $inspector = new ProviderCapacityInspector();

        $before = time();
        $reports = $inspector->inspect([
            'providers' => [
                'codex' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo json_encode(["session" => ["remaining_percent" => 80, "reset_in_seconds" => 3600], "week" => ["remaining_percent" => 50, "reset_in_seconds" => 7200]]);'],
                ],
            ],
        ], []);

        self::assertCount(1, $reports);
        self::assertNotNull($reports[0]->externalResetAt);
        self::assertGreaterThanOrEqual($before + 7199, $reports[0]->externalResetAt);
    }

    public function testNaiveDateStringsAreParsedAsUtc(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $inspector = new ProviderCapacityInspector();
        $reports = $inspector->inspect([
            'providers' => [
                'gemini' => [
                    'enabled' => true,
                    'daily_budget' => 20,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo "gemini 50% left resets 2026-05-10 15:00:00\n";'],
                ],
            ],
        ], []);

        self::assertCount(1, $reports);
        self::assertSame(gmmktime(15, 0, 0, 5, 10, 2026), $reports[0]->externalResetAt);
    }
}
