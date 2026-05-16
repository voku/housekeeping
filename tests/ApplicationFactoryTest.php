<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderBackedTask;
use HousekeepingAgentCron\Runtime\ApplicationFactory;
use PHPUnit\Framework\TestCase;

final class ApplicationFactoryTest extends TestCase
{
    public function testFactoryBuildsConfiguredTasks(): void
    {
        $factory = new ApplicationFactory();
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/tasks.php';
        $tasks = $factory->tasks($config);

        self::assertSame(
            ['project:discover', 'commits:learn', 'blindspots:analyze', 'docs:refresh', 'todo:refine', 'self-improve:housekeeping', 'deps:audit', 'phpstan:suggest-fixes', 'slop:scan'],
            array_map(static fn ($task) => $task->name(), $tasks),
        );
    }

    public function testFactoryAlwaysIncludesNullProvider(): void
    {
        $factory = new ApplicationFactory();
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/tasks.php';
        $providers = $factory->providers($config);

        self::assertArrayHasKey('local-null-provider', $providers);
        self::assertArrayNotHasKey('codex', $providers);
        self::assertArrayNotHasKey('gemini', $providers);
        self::assertArrayNotHasKey('copilot', $providers);
        self::assertArrayNotHasKey('claude', $providers);
        self::assertArrayNotHasKey('opencode', $providers);
    }

    public function testFactoryIncludesEveryExplicitlyEnabledExternalProvider(): void
    {
        $factory = new ApplicationFactory();
        $providers = $factory->providers([
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
                'codex' => [
                    'enabled' => true,
                    'command' => ['php', '-r', 'fwrite(STDOUT, "ok");'],
                    'working_directory' => __DIR__,
                ],
                'gemini' => [
                    'enabled' => true,
                    'command' => ['php', '-r', 'fwrite(STDOUT, "ok");'],
                    'working_directory' => __DIR__,
                ],
                'copilot' => [
                    'enabled' => true,
                    'command' => ['php', '-r', 'fwrite(STDOUT, "ok");'],
                    'working_directory' => __DIR__,
                ],
                'claude' => [
                    'enabled' => true,
                    'command' => ['php', '-r', 'fwrite(STDOUT, "ok");'],
                    'working_directory' => __DIR__,
                ],
                'opencode' => [
                    'enabled' => true,
                    'command' => ['php', '-r', 'fwrite(STDOUT, "ok");'],
                    'working_directory' => __DIR__,
                ],
            ],
        ]);

        self::assertSame(['local-null-provider', 'codex', 'gemini', 'copilot', 'claude', 'opencode'], array_keys($providers));
        self::assertTrue($providers['codex']->isAvailable($this->runContext($providers)));
        self::assertTrue($providers['gemini']->isAvailable($this->runContext($providers)));
        self::assertTrue($providers['copilot']->isAvailable($this->runContext($providers)));
        self::assertTrue($providers['claude']->isAvailable($this->runContext($providers)));
        self::assertTrue($providers['opencode']->isAvailable($this->runContext($providers)));
    }

    public function testFactoryDoesNotEnableExternalProviderWithoutEnabledFlag(): void
    {
        $factory = new ApplicationFactory();
        $providers = $factory->providers([
            'providers' => [
                'codex' => [
                    'command' => ['php', '-r', 'fwrite(STDOUT, "ok");'],
                    'working_directory' => __DIR__,
                ],
            ],
        ]);

        self::assertSame(['local-null-provider'], array_keys($providers));
    }

    public function testFactoryUsesRepositoryRootForProviderWorkingDirectoryByDefault(): void
    {
        $repositoryRoot = sys_get_temp_dir() . '/agent-cron-provider-root-' . bin2hex(random_bytes(4));
        mkdir($repositoryRoot, 0777, true);

        try {
            $factory = new ApplicationFactory();
            $providers = $factory->providers([
                'paths' => [
                    'repository_root' => $repositoryRoot,
                ],
                'providers' => [
                    'codex' => [
                        'enabled' => true,
                        'command' => ['php', '-r', 'echo getcwd();'],
                        'append_yolo' => false,
                    ],
                ],
            ]);

            $result = $providers['codex']->execute(new \HousekeepingAgentCron\Runtime\ProviderRequest('docs:refresh', 'Prompt', []));

            self::assertTrue($result->successful);
            self::assertSame($repositoryRoot, $result->context['stdout'] ?? null);
        } finally {
            rmdir($repositoryRoot);
        }
    }

    public function testFactoryFiltersNonStringProviderArguments(): void
    {
        $factory = new ApplicationFactory();
        $providers = $factory->providers([
            'providers' => [
                'codex' => [
                    'enabled' => true,
                    'command' => ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
                    'arguments' => ['--sandbox', 123, null, 'project-only'],
                    'working_directory' => __DIR__,
                    'append_yolo' => false,
                ],
            ],
        ]);

        $result = $providers['codex']->execute(new \HousekeepingAgentCron\Runtime\ProviderRequest('docs:refresh', 'Prompt', []));

        self::assertTrue($result->successful);
        $stdout = $result->context['stdout'] ?? null;
        self::assertIsString($stdout);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        self::assertSame(['exec', '--sandbox', 'project-only'], array_slice($decoded, 0, 3));
        self::assertSame($result->context['prompt'] ?? null, $decoded[3] ?? null);
    }

    public function testFactoryUsesPackageRootForProviderWorkingDirectoryWhenRepositoryRootIsNotConfigured(): void
    {
        $factory = new ApplicationFactory();
        $providers = $factory->providers([
            'providers' => [
                'codex' => [
                    'enabled' => true,
                    'command' => ['php', '-r', 'echo getcwd();'],
                    'append_yolo' => false,
                ],
            ],
        ]);

        $result = $providers['codex']->execute(new \HousekeepingAgentCron\Runtime\ProviderRequest('docs:refresh', 'Prompt', []));

        self::assertTrue($result->successful);
        self::assertSame(dirname(__DIR__), $result->context['stdout'] ?? null);
    }

    public function testFactoryDoesNotAppendDangerousProviderFlagsByDefault(): void
    {
        $factory = new ApplicationFactory();
        $providers = $factory->providers([
            'providers' => [
                'codex' => [
                    'enabled' => true,
                    'command' => ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
                    'working_directory' => __DIR__,
                ],
            ],
        ]);

        $result = $providers['codex']->execute(new \HousekeepingAgentCron\Runtime\ProviderRequest('docs:refresh', 'Prompt', []));

        self::assertTrue($result->successful);
        $stdout = $result->context['stdout'] ?? null;
        self::assertIsString($stdout);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        self::assertNotContains('--dangerously-bypass-approvals-and-sandbox', $decoded);
        self::assertSame('exec', $decoded[0] ?? null);
    }

    public function testFactoryBuildsPreferredProviderRoutingForAutoTasks(): void
    {
        $factory = new ApplicationFactory();
        $tasks = $factory->tasks([
            'tasks' => [
                'docs:refresh' => [
                    'enabled' => true,
                    'interval_seconds' => 3600,
                    'provider' => 'auto',
                    'preferred_providers' => ['claude', 123, 'codex'],
                    'input_files' => [__FILE__],
                ],
            ],
        ]);

        self::assertCount(1, $tasks);
        self::assertInstanceOf(ProviderBackedTask::class, $tasks[0]);
        self::assertSame('auto', $tasks[0]->providerName());
        self::assertSame(['claude', 'codex'], $tasks[0]->preferredProviderNames());
    }

    public function testFactoryBuildsGenericSelectedFilesMaintenanceTask(): void
    {
        $factory = new ApplicationFactory();
        $tasks = $factory->tasks([
            'tasks' => [
                'phpdocs:refresh' => [
                    'enabled' => true,
                    'interval_seconds' => 3600,
                    'provider' => 'auto',
                    'preferred_providers' => ['gemini', 'copilot'],
                    'working_directory' => __DIR__,
                    'selection_command' => ['php', '-r', ''],
                    'prompt' => 'Refresh phpdocs.',
                    'success_message' => 'Done.',
                    'context_files' => [__FILE__],
                    'max_files' => 7,
                ],
            ],
        ]);

        self::assertCount(1, $tasks);
        self::assertInstanceOf(ProviderBackedTask::class, $tasks[0]);
        self::assertSame('phpdocs:refresh', $tasks[0]->name());
        self::assertSame('auto', $tasks[0]->providerName());
        self::assertSame(['gemini', 'copilot'], $tasks[0]->preferredProviderNames());
    }

    public function testFactoryBuildsSelfImprovementTask(): void
    {
        $factory = new ApplicationFactory();
        $tasks = $factory->tasks([
            'tasks' => [
                'self-improve:housekeeping' => [
                    'enabled' => true,
                    'interval_seconds' => 3600,
                    'provider' => 'auto',
                    'preferred_providers' => ['gemini', 'copilot'],
                    'working_directory' => __DIR__,
                    'scope_paths' => ['src'],
                    'validation_commands' => [
                        ['php', '-r', ''],
                    ],
                    'run_threshold' => 7,
                    'recent_run_limit' => 5,
                    'log_entry_limit' => 20,
                ],
            ],
        ]);

        self::assertCount(1, $tasks);
        self::assertInstanceOf(ProviderBackedTask::class, $tasks[0]);
        self::assertSame('self-improve:housekeeping', $tasks[0]->name());
        self::assertSame('auto', $tasks[0]->providerName());
        self::assertSame(['gemini', 'copilot'], $tasks[0]->preferredProviderNames());
    }

    public function testDefaultSelfImprovementValidationCommandsStayLockSafeAndQuotaSafe(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/tasks.php';
        $tasks = $config['tasks'] ?? null;
        self::assertIsArray($tasks);

        $taskConfig = $tasks['self-improve:housekeeping'] ?? null;
        self::assertIsArray($taskConfig);

        $commands = $taskConfig['validation_commands'] ?? null;

        self::assertIsArray($commands);
        self::assertCount(4, $commands);

        $firstCommand = $commands[0] ?? [];
        self::assertIsArray($firstCommand);
        $phpstanPath = $firstCommand[1] ?? '';
        self::assertIsString($phpstanPath);
        self::assertStringEndsWith('phpstan', $phpstanPath);

        $commandNames = [];
        foreach (array_slice($commands, 1) as $command) {
            self::assertIsArray($command);

            $commandName = $command[2] ?? '';
            self::assertIsScalar($commandName);
            if ($commandName !== '') {
                $commandNames[] = (string) $commandName;
            }
        }

        self::assertSame(
            ['housekeeping:list', 'housekeeping:state'],
            $commandNames,
        );
    }

    /**
     * @param array<string, \HousekeepingAgentCron\Contract\ProviderAdapter> $providers
     */
    private function runContext(array $providers): \HousekeepingAgentCron\Runtime\RunContext
    {
        return new \HousekeepingAgentCron\Runtime\RunContext(
            false,
            null,
            time(),
            ['providers' => []],
            ['tasks' => [], 'providers' => [], 'runs' => []],
            [],
            new InMemoryStateStore(),
            new \HousekeepingAgentCron\Runtime\JsonLogger(sys_get_temp_dir() . '/agent-cron-factory-' . bin2hex(random_bytes(4)) . '.log'),
            $providers,
        );
    }
}
