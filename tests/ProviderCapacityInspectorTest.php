<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\ProcessResult;
use HousekeepingAgentCron\Runtime\ProviderCapacityInspector;
use HousekeepingAgentCron\Runtime\ProviderCapacityReport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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

    private function invokePrivate(ProviderCapacityInspector $inspector, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($inspector, $method))->invoke($inspector, ...$args);
    }

    private function report(
        string $provider,
        ?float $ratio,
        ?int $resetAt,
        ?int $budgetRemaining,
        string $status = 'ready',
    ): ProviderCapacityReport {
        return new ProviderCapacityReport(
            $provider,
            true,
            $status,
            $budgetRemaining,
            0,
            $budgetRemaining,
            0,
            $ratio,
            $resetAt,
            null,
            null,
            [],
        );
    }

    /**
     * @param mixed $value
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function assertMetricList(mixed $value): array
    {
        self::assertIsArray($value);
        /** @var list<array{label: string, remaining_ratio: float, reset_at: int|null}> $value */

        return $value;
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

    public function testStatusPriorityValuesRemainStable(): void
    {
        $inspector = new ProviderCapacityInspector();
        $method = new ReflectionMethod($inspector, 'statusPriority');

        self::assertSame(7, $method->invoke($inspector, 'ready'));
        self::assertSame(6, $method->invoke($inspector, 'ready-no-probe'));
        self::assertSame(5, $method->invoke($inspector, 'probe-failed'));
        self::assertSame(4, $method->invoke($inspector, 'external-exhausted'));
        self::assertSame(3, $method->invoke($inspector, 'cooldown-active'));
        self::assertSame(2, $method->invoke($inspector, 'budget-exhausted'));
        self::assertSame(1, $method->invoke($inspector, 'disabled'));
        self::assertSame(0, $method->invoke($inspector, 'unknown'));
    }

    public function testPositiveIntSanitizesInputToNonNegativeIntegers(): void
    {
        $inspector = new ProviderCapacityInspector();

        self::assertSame(2, $this->invokePrivate($inspector, 'positiveInt', 2));
        self::assertSame(1, $this->invokePrivate($inspector, 'positiveInt', 1));
        self::assertSame(0, $this->invokePrivate($inspector, 'positiveInt', 0));
        self::assertSame(0, $this->invokePrivate($inspector, 'positiveInt', -1));
        self::assertSame(0, $this->invokePrivate($inspector, 'positiveInt', 2.5));
        self::assertSame(0, $this->invokePrivate($inspector, 'positiveInt', '2'));
        self::assertSame(0, $this->invokePrivate($inspector, 'positiveInt', ['2']));
    }

    public function testInspectDistinguishesDisabledExternalExhaustedAndBudgetExhaustedProviders(): void
    {
        $inspector = new ProviderCapacityInspector();
        $today = gmdate('Y-m-d');

        $reports = $inspector->inspect([
            'providers' => [
                'disabled-default' => [],
                'external-zero' => [
                    'enabled' => true,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo json_encode(["free" => ["remaining_ratio" => 0.0, "reset_in_seconds" => 60]]);'],
                ],
                'budgeted' => [
                    'enabled' => true,
                    'daily_budget' => 1,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo json_encode(["free" => ["remaining_ratio" => 0.5, "reset_in_seconds" => 60]]);'],
                ],
            ],
        ], [
            'providers' => [
                'budgeted' => [
                    'usage' => [$today => 5],
                ],
            ],
        ]);

        $indexed = [];
        foreach ($reports as $report) {
            $indexed[$report->provider] = $report;
        }

        self::assertFalse($indexed['disabled-default']->enabled);
        self::assertSame('disabled', $indexed['disabled-default']->status);
        self::assertNull($indexed['disabled-default']->internalBudget);
        self::assertNull($indexed['disabled-default']->internalBudgetRemaining);

        self::assertSame('external-exhausted', $indexed['external-zero']->status);
        self::assertNull($indexed['external-zero']->internalBudget);
        self::assertNull($indexed['external-zero']->internalBudgetRemaining);
        self::assertSame(0.0, $indexed['external-zero']->externalRemainingRatio);

        self::assertSame('budget-exhausted', $indexed['budgeted']->status);
        self::assertSame(1, $indexed['budgeted']->internalBudget);
        self::assertSame(0, $indexed['budgeted']->internalBudgetRemaining);
    }

    public function testCooldownRemainingSecondsUsesExpectedStatePathAndBoundaries(): void
    {
        $inspector = new ProviderCapacityInspector();
        $now = time();
        $state = [
            'providers' => [
                'alpha' => [
                    'last_used_at' => $now,
                ],
            ],
        ];

        self::assertSame(1, $this->invokePrivate($inspector, 'cooldownRemainingSeconds', ['cooldown_seconds' => 1], $state, 'alpha', $now));
        self::assertSame(10, $this->invokePrivate($inspector, 'cooldownRemainingSeconds', ['cooldown_seconds' => 10], $state, 'alpha', $now));
        self::assertSame(0, $this->invokePrivate($inspector, 'cooldownRemainingSeconds', [], $state, 'alpha', $now));
    }

    public function testMetricsFromOutputFallsBackToStderrText(): void
    {
        $inspector = new ProviderCapacityInspector();
        $now = time();

        $metrics = $this->assertMetricList($this->invokePrivate($inspector, 'metricsFromOutput', 'fallback', "garbage\n", "fallback 25% left resets 2h\n", $now));

        self::assertCount(1, $metrics);
        self::assertSame('fallback', $metrics[0]['label']);
        self::assertEqualsWithDelta(0.25, $metrics[0]['remaining_ratio'], 0.0001);
        self::assertSame($now + 7200, $metrics[0]['reset_at']);
    }

    public function testMetricsFromJsonKeepsScanningNestedArraysAndUsesPathLabels(): void
    {
        $inspector = new ProviderCapacityInspector();
        $now = time();

        $metrics = $this->assertMetricList($this->invokePrivate($inspector, 'metricsFromJson', [
            'skip' => 'not-an-array',
            'session' => [
                'remaining_percent' => 80,
                'reset_in_seconds' => 60,
            ],
            'limits' => [
                'week' => [
                    'remaining_percent' => 50,
                    'reset_in_seconds' => 120,
                ],
            ],
        ], [], $now));

        self::assertCount(2, $metrics);
        self::assertSame('session', $metrics[0]['label']);
        self::assertSame('week', $metrics[1]['label']);
        self::assertSame($now + 60, $metrics[0]['reset_at']);
        self::assertSame($now + 120, $metrics[1]['reset_at']);
    }

    public function testMetricsFromTextNormalizesUsedModeAndDeduplicatesMetrics(): void
    {
        $inspector = new ProviderCapacityInspector();
        $now = time();

        $metrics = $this->assertMetricList($this->invokePrivate($inspector, 'metricsFromText', 'fallback', "  label-one : 3.5% USED resets 1D2H3m4s\nlabel-one : 3.5% USED resets 1D2H3m4s\n", $now));

        self::assertCount(1, $metrics);
        self::assertSame('label-one', $metrics[0]['label']);
        self::assertEqualsWithDelta(0.965, $metrics[0]['remaining_ratio'], 0.0001);
        self::assertSame($now + 93784, $metrics[0]['reset_at']);
    }

    public function testExtractRemainingRatioSupportsUsedPercentAndUsedTotalPairs(): void
    {
        $inspector = new ProviderCapacityInspector();

        self::assertSame(0.75, $this->invokePrivate($inspector, 'extractRemainingRatio', ['used_percent' => 25]));
        self::assertSame(0.75, $this->invokePrivate($inspector, 'extractRemainingRatio', ['used' => 2.0, 'total' => 8.0]));
        self::assertNull($this->invokePrivate($inspector, 'extractRemainingRatio', ['total' => 8.0]));
        self::assertNull($this->invokePrivate($inspector, 'extractRemainingRatio', ['used' => 2.0, 'total' => 0.0]));
    }

    public function testMetricLabelTrimsValuesAndFallsBackToPathOrLimit(): void
    {
        $inspector = new ProviderCapacityInspector();

        self::assertSame('Primary', $this->invokePrivate($inspector, 'metricLabel', ['label' => '  Primary  '], []));
        self::assertSame('week', $this->invokePrivate($inspector, 'metricLabel', [], ['session', 'week']));
        self::assertSame('limit', $this->invokePrivate($inspector, 'metricLabel', [], []));
    }

    public function testParseResetValueHandlesWhitespaceRelativeStringsAndInvalidValues(): void
    {
        $inspector = new ProviderCapacityInspector();
        $now = time();

        self::assertSame($now + 93784, $this->invokePrivate($inspector, 'parseResetValue', " 1D2H3m4s \n", $now));
        self::assertNull($this->invokePrivate($inspector, 'parseResetValue', 'not-a-reset', $now));
        self::assertNull($this->invokePrivate($inspector, 'relativeSeconds', 'not-a-reset'));
    }

    public function testCompareUsesRatioResetBudgetAndNameOrdering(): void
    {
        $inspector = new ProviderCapacityInspector();

        self::assertLessThan(0, $this->invokePrivate($inspector, 'compare', $this->report('alpha', 0.8, 200, 5), $this->report('beta', 0.5, 100, 5)));
        self::assertLessThan(0, $this->invokePrivate($inspector, 'compare', $this->report('alpha', 0.5, 100, 5), $this->report('beta', 0.5, 200, 5)));
        self::assertLessThan(0, $this->invokePrivate($inspector, 'compare', $this->report('alpha', 0.5, 100, 6), $this->report('beta', 0.5, 100, 5)));
        self::assertLessThan(0, $this->invokePrivate($inspector, 'compare', $this->report('alpha', 0.5, 100, 5), $this->report('beta', 0.5, 100, 5)));
    }

    public function testProbeProviderUsesDefaultWorkingDirectory(): void
    {
        $inspector = new ProviderCapacityInspector();

        $probe = $this->invokePrivate($inspector, 'probeProvider', 'alpha', [
            'enabled' => true,
            'resource_command' => [
                PHP_BINARY,
                '-r',
                'if (!file_exists("composer.json")) { fwrite(STDERR, "missing composer.json"); exit(1); } echo json_encode(["remaining_percent" => 50, "reset_in_seconds" => 30]);',
            ],
        ], time());
        self::assertIsArray($probe);

        self::assertSame('ok', $probe['status']);
        self::assertSame(0.5, $probe['external_remaining_ratio']);
        self::assertSame('Parsed 1 external limit(s).', $probe['probe_message']);
    }

    public function testLatestResetStaysAtMaximumEvenWhenLaterMetricIsSmaller(): void
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
                    'resource_command' => ['php', '-r', 'echo json_encode(["week" => ["remaining_percent" => 50, "reset_in_seconds" => 7200], "session" => ["remaining_percent" => 80, "reset_in_seconds" => 3600]]);'],
                ],
            ],
        ], []);

        self::assertCount(1, $reports);
        self::assertNotNull($reports[0]->externalResetAt);
        self::assertGreaterThanOrEqual($before + 7199, $reports[0]->externalResetAt);
    }

    public function testFailureMessagePrefersTimeoutExceptionOutputAndExitCode(): void
    {
        $inspector = new ProviderCapacityInspector();

        self::assertSame('Probe command timed out.', $this->invokePrivate($inspector, 'failureMessage', new ProcessResult([], 1, '', '', true, __DIR__)));
        self::assertSame('boom', $this->invokePrivate($inspector, 'failureMessage', new ProcessResult([], 1, '', '', false, __DIR__, 'boom')));
        self::assertSame('stdout' . PHP_EOL . 'stderr', $this->invokePrivate($inspector, 'failureMessage', new ProcessResult([], 1, 'stdout', 'stderr', false, __DIR__)));
        self::assertSame('Probe command failed with exit code 9.', $this->invokePrivate($inspector, 'failureMessage', new ProcessResult([], 9, '', '', false, __DIR__)));
    }
}
