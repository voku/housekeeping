<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderBackedTask;
use HousekeepingAgentCron\Runtime\ApplicationFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApplicationFactoryTest extends TestCase
{
    public function testFactoryBuildsConfiguredTasks(): void
    {
        $factory = new ApplicationFactory();
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/tasks.php';
        $tasks = $factory->tasks($config);

        self::assertSame(
            ['project:discover', 'commits:learn', 'blindspots:analyze', 'docs:refresh', 'skills:sync', 'todo:refine', 'self-improve:housekeeping', 'deps:audit', 'phpstan:suggest-fixes', 'slop:scan'],
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

    public function testFactoryLoadConfigFailsCleanlyWhenFileIsMissing(): void
    {
        $factory = new ApplicationFactory();
        $missingConfigFile = sys_get_temp_dir() . '/agent-cron-missing-config-' . bin2hex(random_bytes(4)) . '.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Config file not found: ' . $missingConfigFile);

        $factory->loadConfig($missingConfigFile);
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

    public function testFactoryMapsFirstClassProviderModelConfigToCliFlag(): void
    {
        $factory = new ApplicationFactory();
        $providers = $factory->providers([
            'providers' => [
                'codex' => [
                    'enabled' => true,
                    'command' => ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
                    'arguments' => ['--sandbox', 'workspace-write'],
                    'model' => 'gpt-5.5',
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
        self::assertSame(['exec', '--sandbox', 'workspace-write', '--model', 'gpt-5.5'], array_slice($decoded, 0, 5));
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

    public function testProjectTemplateIncludesGenericSkillSyncTask(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/project-template.php';
        $tasks = $config['tasks'] ?? null;
        self::assertIsArray($tasks);

        $taskConfig = $tasks['skills:sync'] ?? null;
        self::assertIsArray($taskConfig);
        self::assertTrue($taskConfig['enabled'] ?? false);
        self::assertSame(86400, $taskConfig['interval_seconds'] ?? null);
        self::assertSame(95, $taskConfig['priority'] ?? null);
        self::assertSame('local-null-provider', $taskConfig['provider'] ?? null);
        self::assertIsArray($taskConfig['selection_command'] ?? null);
        self::assertSame('Skill file sync completed.', $taskConfig['success_message'] ?? null);
    }

    public function testExampleProjectConfigUsesHardenedSelectorsAndProjectSlopScanCommand(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/example-project.php';
        $tasks = $config['tasks'] ?? null;
        self::assertIsArray($tasks);

        $phpdocTask = $tasks['phpdocs:refresh'] ?? null;
        self::assertIsArray($phpdocTask);
        $phpdocSelectionCommand = $phpdocTask['selection_command'] ?? null;
        self::assertIsArray($phpdocSelectionCommand);
        self::assertSame(
            'git log --since="120 days ago" --name-only --pretty=format: -- "*.php" | awk \'NF && !seen[$0]++\' | head -n 12 || true',
            $phpdocSelectionCommand[2] ?? null,
        );

        $magicNumbersTask = $tasks['magic-numbers:reduce'] ?? null;
        self::assertIsArray($magicNumbersTask);
        $magicNumbersSelectionCommand = $magicNumbersTask['selection_command'] ?? null;
        self::assertIsArray($magicNumbersSelectionCommand);
        self::assertSame(
            'git grep -l -iE "TODO.*(magic number|constant|hardcoded)" -- "*.php" | head -n 10 || true',
            $magicNumbersSelectionCommand[2] ?? null,
        );

        $todoCommentsTask = $tasks['todo-comments:cleanup'] ?? null;
        self::assertIsArray($todoCommentsTask);
        $todoCommentsSelectionCommand = $todoCommentsTask['selection_command'] ?? null;
        self::assertIsArray($todoCommentsSelectionCommand);
        self::assertSame(
            'git grep -l -E "TODO\\s*@" -- "*.php" | head -n 10 || true',
            $todoCommentsSelectionCommand[2] ?? null,
        );

        $smallRefactorsTask = $tasks['small-refactors:polish'] ?? null;
        self::assertIsArray($smallRefactorsTask);
        $smallRefactorsSelectionCommand = $smallRefactorsTask['selection_command'] ?? null;
        self::assertIsArray($smallRefactorsSelectionCommand);
        self::assertSame(
            'git log --since="90 days ago" --name-only --pretty=format: -- "*.php" | awk \'NF && !seen[$0]++\' | head -n 10 || true',
            $smallRefactorsSelectionCommand[2] ?? null,
        );
        self::assertIsString($smallRefactorsTask['prompt'] ?? null);
        self::assertStringContainsString('regenerate the autoloader map', $smallRefactorsTask['prompt']);

        $slopScanTask = $tasks['slop:scan'] ?? null;
        self::assertIsArray($slopScanTask);
        $slopScanCommand = $slopScanTask['command'] ?? null;
        self::assertIsArray($slopScanCommand);
        self::assertSame(
            'cd /var/www/html && php -d memory_limit=2G vendor/slop-scan.phar scan /var/www/html --config-file infra/githooks/slop-scan.config.json --cache-file /tmp/example-project-slop-scan-cache.json --baseline-file /var/www/html/infra/githooks/slop-scan-baseline.toon --min-score 2 --json',
            $slopScanCommand[7] ?? null,
        );
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

        $secondCommand = $commands[1] ?? [];
        self::assertIsArray($secondCommand);
        $phpunitPath = $secondCommand[1] ?? '';
        self::assertIsString($phpunitPath);
        self::assertStringEndsWith('phpunit', $phpunitPath);

        $thirdCommand = $commands[2] ?? [];
        self::assertIsArray($thirdCommand);
        self::assertSame('housekeeping:list', $thirdCommand[2] ?? null);

        $fourthCommand = $commands[3] ?? [];
        self::assertIsArray($fourthCommand);
        self::assertSame('housekeeping:state', $fourthCommand[2] ?? null);
    }

    public function testDefaultDogfoodConfiguredTaskFilesExist(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/tasks.php';
        $tasks = $config['tasks'] ?? null;
        self::assertIsArray($tasks);

        foreach ($tasks as $taskName => $taskConfig) {
            if (!is_string($taskName) || !is_array($taskConfig) || ($taskConfig['enabled'] ?? false) !== true) {
                continue;
            }

            foreach (['input_files', 'context_files'] as $key) {
                $files = $taskConfig[$key] ?? [];
                if (!is_array($files)) {
                    continue;
                }

                foreach ($files as $path) {
                    if (!is_string($path) || $path === '') {
                        continue;
                    }

                    self::assertFileExists($path, sprintf('Configured %s file for %s is missing: %s', $key, $taskName, $path));
                }
            }
        }
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
