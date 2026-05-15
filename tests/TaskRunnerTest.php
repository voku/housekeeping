<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\HousekeepingTask;
use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Provider\NullProvider;
use HousekeepingAgentCron\Runtime\ExitCode;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;
use HousekeepingAgentCron\Runtime\TaskRunner;
use HousekeepingAgentCron\Task\AbstractProviderTask;
use HousekeepingAgentCron\Task\DocumentationRefreshTask;
use HousekeepingAgentCron\Task\SelfImprovementTask;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

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
        self::assertIsString($this->stateAt($store->state, 'runs.0.run_id'));
        self::assertSame('completed', $this->stateAt($store->state, 'runs.0.status'));
        self::assertSame([], $this->stateAt($store->state, 'runs.0.errors'));
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
        self::assertSame('failed', $this->stateAt($store->state, 'runs.0.status'));
        self::assertSame('boom', $this->stateAt($store->state, 'runs.0.errors.0.message'));
        self::assertSame('fail:test', $this->stateAt($store->state, 'runs.0.errors.0.task'));
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

    public function testVerboseOutputReportsTaskLifecycle(): void
    {
        $store = new InMemoryStateStore();
        $context = $this->context(false, $store);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE, false);

        $exitCode = (new TaskRunner([$this->plainTask('plain:verbose')]))->run($context, $output);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        $display = $output->fetch();
        self::assertMatchesRegularExpression('/^\[[^\]]+\] \[run\] plain:verbose/m', $display);
        self::assertMatchesRegularExpression('/^\[[^\]]+\] \[ok\] plain:verbose: Plain task completed\./m', $display);
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

    public function testAutoProviderRoutingPrefersTaskSpecificProviderOverGlobalRanking(): void
    {
        /** @var ProviderAdapter&object{calls: int} $codex */
        $codex = $this->recordingProvider('codex');
        /** @var ProviderAdapter&object{calls: int} $gemini */
        $gemini = $this->recordingProvider('gemini');
        $store = new InMemoryStateStore();
        $context = $this->context(false, $store, ['codex' => $codex, 'gemini' => $gemini], [
            'providers' => [
                'codex' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                    'resource_command' => $this->providerProbeCommand(20),
                ],
                'gemini' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                    'resource_command' => $this->providerProbeCommand(90),
                ],
            ],
        ]);
        $task = new readonly class extends AbstractProviderTask {
            public function __construct()
            {
                parent::__construct(3600, 'auto', ['codex']);
            }

            public function name(): string
            {
                return 'auto:provider';
            }

            public function run(RunContext $context): TaskResult
            {
                return $this->executeProvider($context, 'Pick the best provider.', [], 'Auto provider completed.');
            }
        };

        $exitCode = (new TaskRunner([$task]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame(1, $codex->calls);
        self::assertSame(0, $gemini->calls);
        self::assertSame('codex', $this->stateAt($store->state, 'runs.0.results.0.context.provider'));
        self::assertSame('auto', $context->runtimeValue('task_provider_routes.auto:provider.configured_provider'));
        self::assertSame(['codex'], $context->runtimeValue('task_provider_routes.auto:provider.preferred_providers'));
        self::assertSame('codex', $context->runtimeValue('task_provider_routes.auto:provider.resolved_provider'));
        self::assertSame('preferred_provider', $this->stateAt($store->state, 'runs.0.results.0.context.routing_reason'));
    }

    public function testAutoProviderRoutingFallsBackToGlobalRankingWhenPreferredProviderIsNotReady(): void
    {
        /** @var ProviderAdapter&object{calls: int} $codex */
        $codex = $this->recordingProvider('codex');
        /** @var ProviderAdapter&object{calls: int} $gemini */
        $gemini = $this->recordingProvider('gemini');
        $store = new InMemoryStateStore();
        $context = $this->context(false, $store, ['codex' => $codex, 'gemini' => $gemini], [
            'providers' => [
                'codex' => [
                    'enabled' => false,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                    'resource_command' => $this->providerProbeCommand(80),
                ],
                'gemini' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                    'resource_command' => $this->providerProbeCommand(90),
                ],
            ],
        ]);
        $task = new readonly class extends AbstractProviderTask {
            public function __construct()
            {
                parent::__construct(3600, 'auto', ['codex']);
            }

            public function name(): string
            {
                return 'auto:fallback';
            }

            public function run(RunContext $context): TaskResult
            {
                return $this->executeProvider($context, 'Pick the best provider.', [], 'Auto provider completed.');
            }
        };

        $exitCode = (new TaskRunner([$task]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame(0, $codex->calls);
        self::assertSame(1, $gemini->calls);
        self::assertSame('gemini', $this->stateAt($store->state, 'runs.0.results.0.context.provider'));
        self::assertSame('global_readiness_ranking', $this->stateAt($store->state, 'runs.0.results.0.context.routing_reason'));
    }

    public function testAutoProviderRoutingReusesPreferredProviderWithinSameRunDespiteCooldown(): void
    {
        /** @var ProviderAdapter&object{calls: int} $gemini */
        $gemini = $this->recordingProvider('gemini');
        /** @var ProviderAdapter&object{calls: int} $copilot */
        $copilot = $this->recordingProvider('copilot');
        $store = new InMemoryStateStore();
        $context = $this->context(false, $store, ['gemini' => $gemini, 'copilot' => $copilot], [
            'max_tasks_per_run' => 2,
            'providers' => [
                'gemini' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 900,
                ],
                'copilot' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                ],
            ],
        ]);
        $taskFactory = static fn (string $taskName): AbstractProviderTask => new readonly class ($taskName) extends AbstractProviderTask {
            public function __construct(private string $taskName)
            {
                parent::__construct(3600, 'auto', ['gemini', 'copilot']);
            }

            public function name(): string
            {
                return $this->taskName;
            }

            public function run(RunContext $context): TaskResult
            {
                return $this->executeProvider($context, 'Reuse the same provider in one run.', [], 'Auto provider completed.');
            }
        };

        $exitCode = (new TaskRunner([$taskFactory('auto:first'), $taskFactory('auto:second')]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame(2, $gemini->calls);
        self::assertSame(0, $copilot->calls);
        self::assertSame('gemini', $this->stateAt($store->state, 'runs.0.results.0.context.provider'));
        self::assertSame('gemini', $this->stateAt($store->state, 'runs.0.results.1.context.provider'));
        self::assertSame('preferred_provider', $this->stateAt($store->state, 'runs.0.results.1.context.routing_reason'));
    }

    public function testProviderBackedTaskResultReceivesProviderRoutingContext(): void
    {
        $provider = $this->recordingProvider('codex');
        $store = new InMemoryStateStore();
        $context = $this->context(false, $store, ['codex' => $provider], [
            'providers' => [
                'codex' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                ],
            ],
        ]);
        $task = new readonly class implements \HousekeepingAgentCron\Contract\ProviderBackedTask {
            public function name(): string
            {
                return 'provider:context';
            }

            public function providerName(): string
            {
                return 'codex';
            }

            public function preferredProviderNames(): array
            {
                return [];
            }

            public function isDue(RunContext $context): bool
            {
                return true;
            }

            public function run(RunContext $context): TaskResult
            {
                return TaskResult::success('Done.');
            }
        };

        $exitCode = (new TaskRunner([$task]))->run($context);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('codex', $this->stateAt($store->state, 'runs.0.results.0.context.provider'));
        self::assertSame('codex', $this->stateAt($store->state, 'runs.0.results.0.context.configured_provider'));
    }

    public function testRevertedSelfImprovementDoesNotFailWholeRun(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-task-runner-self-improve-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir . '/logs');
        file_put_contents($dir . '/README.md', "Before\n");

        $provider = new class ($dir . '/README.md') implements ProviderAdapter {
            public function __construct(private readonly string $readmeFile)
            {
            }

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
                file_put_contents($this->readmeFile, "Broken\n");

                return ProviderResult::success('Accepted.');
            }
        };

        try {
            $task = new SelfImprovementTask(
                1,
                'local-null-provider',
                new ProcessExecutor(),
                $dir,
                ['README.md'],
                [$dir . '/README.md'],
                [['php', '-r', 'exit(1);']],
                1,
            );
            $store = new InMemoryStateStore([
                'tasks' => [],
                'providers' => [],
                'runs' => [
                    ['started_at' => 100, 'dry_run' => false, 'exit_code' => 0, 'results' => [['task' => 'todo:refine', 'successful' => true, 'message' => 'TODO refinement completed.']]],
                ],
            ]);
            $context = $this->context(false, $store, ['local-null-provider' => $provider], [
                'paths' => [
                    'repository_root' => $dir,
                    'logs' => $dir . '/logs',
                ],
            ]);

            $exitCode = (new TaskRunner([$task]))->run($context);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            self::assertSame("Before\n", file_get_contents($dir . '/README.md'));
            self::assertSame(
                'Self-improvement proposal failed validation and was reverted.',
                $this->stateAt($store->state, 'runs.1.results.0.message'),
            );
            self::assertTrue($this->stateAt($store->state, 'runs.1.results.0.context.reverted'));
            self::assertTrue($this->stateAt($store->state, 'tasks.self-improve:housekeeping.last_successful'));
        } finally {
            (new Filesystem())->remove($dir);
        }
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
            [],
            $store,
            new JsonLogger($logFile ?? sys_get_temp_dir() . '/agent-cron-test-' . bin2hex(random_bytes(4)) . '.log'),
            $providers,
        );
    }

    /**
     * @return list<string>
     */
    private function providerProbeCommand(int $remainingPercent): array
    {
        return [
            PHP_BINARY,
            '-r',
            sprintf('echo json_encode(["remaining_percent" => %d], JSON_UNESCAPED_SLASHES);', $remainingPercent),
        ];
    }

    /**
     * @return ProviderAdapter&object{calls: int}
     */
    private function recordingProvider(string $name): ProviderAdapter
    {
        return new class ($name) implements ProviderAdapter {
            public int $calls = 0;

            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function isAvailable(RunContext $context): bool
            {
                return true;
            }

            public function execute(ProviderRequest $request): ProviderResult
            {
                ++$this->calls;

                return ProviderResult::success('Accepted.', ['provider' => $this->name]);
            }
        };
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
