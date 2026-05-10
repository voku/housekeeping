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
        self::assertTrue($this->stateAt($store->state, 'tasks.docs:refresh.last_successful'));
        self::assertSame(1, $this->stateAt($store->state, 'providers.local-null-provider.usage.' . gmdate('Y-m-d')));
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
    private function context(bool $dryRun, InMemoryStateStore $store, array $providers = [], array $configOverrides = [], ?int $startedAt = null): RunContext
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
            null,
            $startedAt ?? time(),
            $config,
            $store->load(),
            $store,
            new JsonLogger(sys_get_temp_dir() . '/agent-cron-test-' . bin2hex(random_bytes(4)) . '.log'),
            $providers,
        );
    }
}
