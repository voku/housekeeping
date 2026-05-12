<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\SelfImprovementTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SelfImprovementTaskTest extends TestCase
{
    use StateAssertions;

    public function testSelfImprovementIsNotDueBeforeRunThreshold(): void
    {
        $task = new SelfImprovementTask(
            3600,
            'local-null-provider',
            new ProcessExecutor(),
            __DIR__,
            ['.'],
            [],
            [],
            3,
        );

        $context = $this->context(
            __DIR__,
            new class implements ProviderAdapter {
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
                    return ProviderResult::success('Accepted.');
                }
            },
            [
                ['started_at' => 100, 'dry_run' => false, 'results' => []],
                ['started_at' => 200, 'dry_run' => false, 'results' => []],
            ],
        );

        self::assertFalse($task->isDue($context));
    }

    public function testSelfImprovementReportsChangedFilesAndStoresReviewMetadata(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-self-improve-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir . '/logs');
        $readmeFile = $dir . '/README.md';
        file_put_contents($readmeFile, "Before\n");
        file_put_contents($dir . '/logs/housekeeping.log', implode(PHP_EOL, [
            json_encode(['ts' => gmdate(DATE_ATOM, 100), 'level' => 'error', 'event' => 'task_result', 'context' => ['task' => 'phpstan:suggest-fixes', 'message' => 'PHPStan command timed out.']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '',
        ]));

        $provider = new class ($readmeFile) implements ProviderAdapter {
            /** @var array<string, mixed>|null */
            public ?array $payload = null;

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
                $this->payload = $request->payload;
                file_put_contents($this->readmeFile, "After\n");

                return ProviderResult::success('Accepted.');
            }
        };

        try {
            $task = new SelfImprovementTask(
                3600,
                'local-null-provider',
                new ProcessExecutor(),
                $dir,
                ['README.md'],
                [$readmeFile],
                [['php', '-r', '']],
                2,
                5,
                10,
                120,
            );

            $context = $this->context($dir, $provider, [
                ['started_at' => 100, 'dry_run' => false, 'exit_code' => 1, 'results' => [['task' => 'phpstan:suggest-fixes', 'successful' => false, 'message' => 'PHPStan command timed out.']]],
                ['started_at' => 200, 'dry_run' => false, 'exit_code' => 0, 'results' => [['task' => 'todo:refine', 'successful' => true, 'message' => 'TODO refinement completed.']]],
            ]);

            $result = $task->run($context);

            self::assertTrue($result->successful);
            self::assertFalse($result->skipped);
            self::assertSame(['README.md'], $result->context['changed_files'] ?? null);
            self::assertSame(200, $this->stateAt($context->state(), 'metadata.self_improvement.last_reviewed_run_started_at'));
            self::assertSame(2, $this->stateAt($context->state(), 'metadata.self_improvement.last_reviewed_run_count'));
            self::assertSame(['README.md'], $this->stateAt($context->state(), 'metadata.self_improvement.last_changed_files'));
            self::assertIsArray($provider->payload);
            self::assertSame(['README.md'], $provider->payload['allowed_scope_paths'] ?? null);
            self::assertNotEmpty($provider->payload['recent_log_entries'] ?? []);
            self::assertNotEmpty($provider->payload['failure_summary'] ?? []);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testSelfImprovementRestoresFilesWhenValidationFails(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-self-improve-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir . '/logs');
        $readmeFile = $dir . '/README.md';
        file_put_contents($readmeFile, "Before\n");

        $provider = new class ($readmeFile) implements ProviderAdapter {
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
                3600,
                'local-null-provider',
                new ProcessExecutor(),
                $dir,
                ['README.md'],
                [$readmeFile],
                [['php', '-r', 'fwrite(STDERR, "fail"); exit(1);']],
                1,
                5,
                10,
                120,
            );

            $context = $this->context($dir, $provider, [
                ['started_at' => 100, 'dry_run' => false, 'exit_code' => 0, 'results' => [['task' => 'todo:refine', 'successful' => true, 'message' => 'TODO refinement completed.']]],
            ]);

            $result = $task->run($context);

            self::assertTrue($result->successful);
            self::assertFalse($result->skipped);
            self::assertSame('Self-improvement proposal failed validation and was reverted.', $result->message);
            self::assertSame("Before\n", file_get_contents($readmeFile));
            self::assertSame([], $this->stateAt($context->state(), 'metadata.self_improvement.last_changed_files'));
            self::assertTrue($result->context['reverted'] ?? false);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    /**
     * @param list<array<string, mixed>> $runs
     */
    private function context(string $repositoryRoot, ProviderAdapter $provider, array $runs): RunContext
    {
        return new RunContext(
            false,
            null,
            time(),
            [
                'paths' => [
                    'repository_root' => $repositoryRoot,
                    'logs' => $repositoryRoot . '/logs',
                ],
                'providers' => [
                    'local-null-provider' => [
                        'enabled' => true,
                        'daily_budget' => 1,
                        'cooldown_seconds' => 0,
                    ],
                ],
            ],
            [
                'tasks' => [],
                'providers' => [],
                'runs' => $runs,
            ],
            [],
            new InMemoryStateStore(),
            new JsonLogger($repositoryRoot . '/logs/housekeeping.log'),
            ['local-null-provider' => $provider],
        );
    }
}
