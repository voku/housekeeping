<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\HousekeepingTask;
use HousekeepingAgentCron\Provider\NullProvider;
use HousekeepingAgentCron\Runtime\ExitCode;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;
use HousekeepingAgentCron\Runtime\TaskRunner;
use HousekeepingAgentCron\Task\DocumentationRefreshTask;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TaskRunnerTest extends TestCase
{
    use StateAssertions;

    public function testDryRunDoesNotCallProviderOrPersistState(): void
    {
        $provider = new NullProvider();
        $store = new InMemoryStateStore();
        $context = $this->context(true, $store, ['local-null-provider' => $provider]);

        $exitCode = (new TaskRunner([new DocumentationRefreshTask(3600, 'local-null-provider', [__DIR__ . '/../config/tasks.php'])]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame(0, $provider->calls);
        self::assertSame(['tasks' => [], 'providers' => [], 'runs' => []], $store->state);
    }

    public function testSuccessfulRunPersistsTaskAndProviderState(): void
    {
        $provider = new NullProvider();
        $store = new InMemoryStateStore();
        $context = $this->context(false, $store, ['local-null-provider' => $provider]);

        $exitCode = (new TaskRunner([new DocumentationRefreshTask(3600, 'local-null-provider', [__DIR__ . '/../config/tasks.php'])]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame(1, $provider->calls);
        self::assertIsInt($this->stateAt($store->state, 'tasks.docs:refresh.last_finished_at'));
        self::assertTrue($this->stateAt($store->state, 'tasks.docs:refresh.last_successful'));
        self::assertSame('Documentation refresh completed.', $this->stateAt($store->state, 'tasks.docs:refresh.last_message'));
        self::assertIsArray($store->state['tasks'] ?? null);
        self::assertArrayNotHasKey('.last_finished_at', $store->state['tasks']);
        self::assertSame(1, $this->stateAt($store->state, 'providers.local-null-provider.usage.' . gmdate('Y-m-d')));
    }

    public function testSuccessfulRunIncrementsExistingProviderUsageAndRecordsLastUse(): void
    {
        $provider = new NullProvider();
        $store = new InMemoryStateStore([
            'tasks' => [],
            'providers' => [
                'local-null-provider' => [
                    'usage' => [gmdate('Y-m-d') => 2],
                ],
            ],
            'runs' => [],
        ]);
        $context = $this->context(false, $store, ['local-null-provider' => $provider]);

        $exitCode = (new TaskRunner([new DocumentationRefreshTask(3600, 'local-null-provider', [__DIR__ . '/../config/tasks.php'])]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame(3, $this->stateAt($store->state, 'providers.local-null-provider.usage.' . gmdate('Y-m-d')));
        self::assertIsInt($this->stateAt($store->state, 'providers.local-null-provider.last_used_at'));
        self::assertIsArray($store->state['providers'] ?? null);
        self::assertArrayNotHasKey('local-null-provider.last_used_at', $store->state['providers']);
    }

    public function testFailedTaskRecordsErrorContext(): void
    {
        $store = new InMemoryStateStore();
        $task = new class implements HousekeepingTask {
            public function name(): string
            {
                return 'fail:test';
            }

            public function isDue(RunContext $context): bool
            {
                return true;
            }

            public function run(RunContext $context): TaskResult
            {
                throw new RuntimeException('boom');
            }
        };

        $exitCode = (new TaskRunner([$task]))->run($this->context(false, $store));

        self::assertSame(ExitCode::TASK_FAILED, $exitCode);
        self::assertSame('boom', $this->stateAt($store->state, 'tasks.fail:test.last_message'));
        self::assertSame(RuntimeException::class, $this->stateAt($store->state, 'runs.0.results.0.context.exception'));
    }

    public function testRuntimeBudgetIsEnforced(): void
    {
        $store = new InMemoryStateStore();
        $context = $this->context(false, $store, [], ['max_run_seconds' => 1], time() - 2);

        $exitCode = (new TaskRunner([new DocumentationRefreshTask(3600, 'local-null-provider', [__DIR__ . '/../config/tasks.php'])]))->run($context);

        self::assertSame(ExitCode::RUNTIME_BUDGET_EXCEEDED, $exitCode);
    }

    public function testRuntimeBudgetAtExactLimitIsLoggedAndStopsRun(): void
    {
        $store = new InMemoryStateStore();
        $logFile = sys_get_temp_dir() . '/agent-cron-budget-' . bin2hex(random_bytes(4)) . '.log';
        $context = $this->context(false, $store, [], ['max_run_seconds' => 1], time() - 1, $logFile);

        $exitCode = (new TaskRunner([
            new DocumentationRefreshTask(3600, 'local-null-provider', [__DIR__ . '/../config/tasks.php']),
            new DocumentationRefreshTask(3600, 'local-null-provider', [__DIR__ . '/../config/tasks.php']),
        ]))->run($context);

        self::assertSame(ExitCode::RUNTIME_BUDGET_EXCEEDED, $exitCode);
        self::assertStringContainsString('"event":"runtime_budget_exceeded"', (string) file_get_contents($logFile));
        self::assertStringContainsString('"max_run_seconds":1', (string) file_get_contents($logFile));
        self::assertSame([], $this->stateAt($store->state, 'runs.0.results'));
    }


    public function testTaskIsSkippedWhenNotDue(): void
    {
        $provider = new NullProvider();
        $store = new InMemoryStateStore([
            'tasks' => [
                'docs:refresh' => [
                    'last_finished_at' => time(),
                ],
            ],
            'providers' => [],
            'runs' => [],
        ]);
        $context = $this->context(false, $store, ['local-null-provider' => $provider]);

        $exitCode = (new TaskRunner([new DocumentationRefreshTask(3600, 'local-null-provider', [__DIR__ . '/../config/tasks.php'])]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame(0, $provider->calls);
        self::assertSame('Task is not due.', $this->stateAt($store->state, 'runs.0.results.0.message'));
    }

    public function testSkippedTaskDoesNotStopLaterDueTask(): void
    {
        $provider = new NullProvider();
        $store = new InMemoryStateStore([
            'tasks' => [
                'docs:refresh' => [
                    'last_finished_at' => time(),
                ],
            ],
            'providers' => [],
            'runs' => [],
        ]);
        $context = $this->context(false, $store, ['local-null-provider' => $provider], ['max_tasks_per_run' => 2]);

        $exitCode = (new TaskRunner([
            new DocumentationRefreshTask(3600, 'local-null-provider', [__DIR__ . '/../config/tasks.php']),
            new class implements HousekeepingTask {
                public function name(): string
                {
                    return 'plain:success';
                }

                public function isDue(RunContext $context): bool
                {
                    return true;
                }

                public function run(RunContext $context): TaskResult
                {
                    return TaskResult::success('Plain task completed.');
                }
            },
        ]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('docs:refresh', $this->stateAt($store->state, 'runs.0.results.0.task'));
        self::assertSame('plain:success', $this->stateAt($store->state, 'runs.0.results.1.task'));
    }

    public function testTaskFilterRunsOnlyMatchingTask(): void
    {
        $store = new InMemoryStateStore();
        $context = $this->context(false, $store, [], ['max_tasks_per_run' => 2], null, null, 'plain:one');

        $exitCode = (new TaskRunner([
            $this->plainTask('plain:one'),
            $this->plainTask('plain:two'),
        ]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        $results = $this->stateAt($store->state, 'runs.0.results');
        self::assertIsArray($results);
        self::assertCount(1, $results);
        self::assertSame('plain:one', $this->stateAt($store->state, 'runs.0.results.0.task'));
    }

    public function testDefaultTaskLimitRunsOnlyOneTask(): void
    {
        $store = new InMemoryStateStore();
        $context = $this->context(false, $store, [], ['max_tasks_per_run' => null]);

        $exitCode = (new TaskRunner([
            $this->plainTask('plain:one'),
            $this->plainTask('plain:two'),
        ]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        $results = $this->stateAt($store->state, 'runs.0.results');
        self::assertIsArray($results);
        self::assertCount(1, $results);
        self::assertSame('plain:one', $this->stateAt($store->state, 'runs.0.results.0.task'));
    }

    public function testDryRunSuccessfulTaskDoesNotPersistTaskState(): void
    {
        $store = new InMemoryStateStore();
        $context = $this->context(true, $store);

        $exitCode = (new TaskRunner([$this->plainTask('plain:dry-run')]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame(['tasks' => [], 'providers' => [], 'runs' => []], $store->state);
    }

    public function testRunHistoryIsTrimmedToLastTwentyRuns(): void
    {
        $existingRuns = [];
        for ($i = 0; $i < 20; ++$i) {
            $existingRuns[] = ['index' => $i];
        }
        $store = new InMemoryStateStore([
            'tasks' => [],
            'providers' => [],
            'runs' => $existingRuns,
        ]);
        $context = $this->context(false, $store);

        $exitCode = (new TaskRunner([$this->plainTask('plain:history')]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertIsArray($store->state['runs'] ?? null);
        self::assertCount(20, $store->state['runs']);
        self::assertSame(1, $this->stateAt($store->state, 'runs.0.index'));
        self::assertSame('plain:history', $this->stateAt($store->state, 'runs.19.results.0.task'));
    }

    public function testProviderQuotaIsCheckedBeforeExecution(): void
    {
        $provider = new NullProvider();
        $store = new InMemoryStateStore([
            'tasks' => [],
            'providers' => [
                'local-null-provider' => [
                    'usage' => [gmdate('Y-m-d') => 1],
                ],
            ],
            'runs' => [],
        ]);
        $context = $this->context(false, $store, ['local-null-provider' => $provider], [
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                    'daily_budget' => 1,
                    'cooldown_seconds' => 0,
                ],
            ],
        ]);

        $exitCode = (new TaskRunner([new DocumentationRefreshTask(3600, 'local-null-provider', [__DIR__ . '/../config/tasks.php'])]))->run($context);

        self::assertSame(ExitCode::PROVIDER_UNAVAILABLE, $exitCode);
        self::assertSame(0, $provider->calls);
        self::assertSame('Provider daily budget is exhausted.', $this->stateAt($store->state, 'runs.0.results.0.message'));
    }

    /**
     * @param array<string, \HousekeepingAgentCron\Contract\ProviderAdapter> $providers
     * @param array<string, mixed> $configOverrides
     */
    private function context(
        bool $dryRun,
        InMemoryStateStore $store,
        array $providers = [],
        array $configOverrides = [],
        ?int $startedAt = null,
        ?string $logFile = null,
        ?string $taskFilter = null,
    ): RunContext
    {
        /** @var array<string, mixed> $config */
        $config = array_replace_recursive([
            'max_run_seconds' => 900,
            'max_tasks_per_run' => 3,
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                    'daily_budget' => 24,
                    'cooldown_seconds' => 0,
                ],
            ],
        ], $configOverrides);

        return new RunContext(
            $dryRun,
            $taskFilter,
            $startedAt ?? time(),
            $config,
            $store->load(),
            $store,
            new JsonLogger($logFile ?? sys_get_temp_dir() . '/agent-cron-test-' . bin2hex(random_bytes(4)) . '.log'),
            $providers,
        );
    }

    private function plainTask(string $name): HousekeepingTask
    {
        return new readonly class ($name) implements HousekeepingTask {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function isDue(RunContext $context): bool
            {
                return true;
            }

            public function run(RunContext $context): TaskResult
            {
                return TaskResult::success('Plain task completed.');
            }
        };
    }
}
