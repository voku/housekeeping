<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\TodoRefinementTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TodoRefinementTaskTest extends TestCase
{
    public function testTodoRefinementIsSkippedWhenProviderDoesNotChangeTodoFiles(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-todo-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $todoFile = $dir . '/TODO.md';
        file_put_contents($todoFile, "Initial TODO\n");

        $provider = new class implements ProviderAdapter {
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
        };

        try {
            $result = (new TodoRefinementTask(3600, 'local-null-provider', [$todoFile]))->run(new RunContext(
                false,
                null,
                time(),
                [
                    'paths' => [
                        'repository_root' => $dir,
                    ],
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
                new JsonLogger($dir . '/logs/housekeeping.log'),
                ['local-null-provider' => $provider],
            ));

            self::assertTrue($result->successful);
            self::assertTrue($result->skipped);
            self::assertSame('TODO refinement produced no TODO document changes.', $result->message);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testTodoRefinementReportsChangedTodoFiles(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-todo-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $todoFile = $dir . '/TODO.md';
        file_put_contents($todoFile, "Initial TODO\n");

        $provider = new class ($todoFile) implements ProviderAdapter {
            public function __construct(private readonly string $todoFile)
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
                file_put_contents($this->todoFile, "Updated TODO\n");

                return ProviderResult::success('Accepted.');
            }
        };

        try {
            $result = (new TodoRefinementTask(3600, 'local-null-provider', [$todoFile]))->run(new RunContext(
                false,
                null,
                time(),
                [
                    'paths' => [
                        'repository_root' => $dir,
                    ],
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
                new JsonLogger($dir . '/logs/housekeeping.log'),
                ['local-null-provider' => $provider],
            ));

            self::assertTrue($result->successful);
            self::assertFalse($result->skipped);
            self::assertSame(['TODO.md'], $result->context['changed_files'] ?? null);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testTodoRefinementIgnoresDiscoveredTodoFilesOutsideConfiguredInputs(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-todo-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $todoFile = $dir . '/TODO.md';
        $ignoredTodoFile = $dir . '/docs/BACKLOG.md';
        (new Filesystem())->mkdir(dirname($ignoredTodoFile));
        file_put_contents($todoFile, "Initial TODO\n");
        file_put_contents($ignoredTodoFile, "Ignored backlog\n");

        $provider = new class implements ProviderAdapter {
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
                $this->payload = $request->payload;

                return ProviderResult::success('Accepted.');
            }
        };

        try {
            $task = new TodoRefinementTask(3600, 'local-null-provider', [$todoFile]);

            $result = $task->run(new RunContext(
                false,
                null,
                time(),
                [
                    'paths' => [
                        'repository_root' => $dir,
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
                    'runs' => [],
                    'metadata' => [
                        'project' => [
                            'todo_files' => ['TODO.md', 'docs/BACKLOG.md'],
                        ],
                    ],
                ],
                [],
                new InMemoryStateStore(),
                new JsonLogger($dir . '/logs/housekeeping.log'),
                ['local-null-provider' => $provider],
            ));

            self::assertTrue($result->successful);
            self::assertTrue($result->skipped);
            self::assertSame(
                ['TODO.md'],
                $provider->payload['todo_file_paths'] ?? null,
            );
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testTodoRefinementForwardsWorkflowContextAndRunsValidation(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-todo-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $todoFile = $dir . '/TODO.md';
        file_put_contents($todoFile, "Initial TODO\n");

        $provider = new class ($todoFile) implements ProviderAdapter {
            /** @var array<string, mixed>|null */
            public ?array $payload = null;

            public function __construct(private readonly string $todoFile)
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
                file_put_contents($this->todoFile, "Updated TODO\n");

                return ProviderResult::success('Accepted.');
            }
        };

        try {
            $task = new TodoRefinementTask(
                3600,
                'local-null-provider',
                [$todoFile],
                new ProcessExecutor(),
                $dir,
                [
                    ['php', '-r', 'fwrite(STDOUT, "status output");'],
                ],
                ['php', '-r', ''],
            );

            $result = $task->run($this->context($dir, $provider));

            self::assertTrue($result->successful);
            self::assertFalse($result->skipped);
            self::assertSame(['TODO.md'], $result->context['changed_files'] ?? null);
            self::assertIsArray($provider->payload);
            self::assertSame(
                [
                    [
                        'command' => ['php', '-r', 'fwrite(STDOUT, "status output");'],
                        'output' => 'status output',
                    ],
                ],
                $provider->payload['workflow_context'] ?? null,
            );
            self::assertSame($dir, $provider->payload['working_directory'] ?? null);
            self::assertSame(['TODO.md'], $provider->payload['todo_file_paths'] ?? null);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testTodoRefinementPromptMentionsBoardSnapshotAndBriefInventory(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-todo-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $todoFile = $dir . '/TODO.md';
        file_put_contents($todoFile, "Initial TODO\n");

        $provider = new class implements ProviderAdapter {
            public ?string $prompt = null;

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
                $this->prompt = $request->prompt;

                return ProviderResult::success('Accepted.');
            }
        };

        try {
            $result = (new TodoRefinementTask(3600, 'local-null-provider', [$todoFile]))->run($this->context($dir, $provider));

            self::assertTrue($result->successful);
            self::assertTrue($result->skipped);
            self::assertIsString($provider->prompt);
            self::assertStringContainsString('Board Snapshot', $provider->prompt);
            self::assertStringContainsString('Agent Task Brief inventory', $provider->prompt);
            self::assertStringContainsString('DONE cards do not keep an active brief', $provider->prompt);
            self::assertStringContainsString('Decision Log', $provider->prompt);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testTodoRefinementFailsWhenBoardValidationFails(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-todo-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $todoFile = $dir . '/TODO.md';
        file_put_contents($todoFile, "Initial TODO\n");

        $provider = new class ($todoFile) implements ProviderAdapter {
            public function __construct(private readonly string $todoFile)
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
                file_put_contents($this->todoFile, "Updated TODO\n");

                return ProviderResult::success('Accepted.');
            }
        };

        try {
            $task = new TodoRefinementTask(
                3600,
                'local-null-provider',
                [$todoFile],
                new ProcessExecutor(),
                $dir,
                [],
                ['php', '-r', 'fwrite(STDERR, "verify failed"); exit(1);'],
            );

            $result = $task->run($this->context($dir, $provider));

            self::assertFalse($result->successful);
            self::assertSame('TODO board validation command failed after refinement.', $result->message);
            self::assertSame("Initial TODO\n", file_get_contents($todoFile));
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    private function context(string $repositoryRoot, ProviderAdapter $provider): RunContext
    {
        return new RunContext(
            false,
            null,
            time(),
            [
                'paths' => [
                    'repository_root' => $repositoryRoot,
                ],
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
            new JsonLogger($repositoryRoot . '/logs/housekeeping.log'),
            ['local-null-provider' => $provider],
        );
    }
}
