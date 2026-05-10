<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

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
            ['project:discover', 'commits:learn', 'blindspots:analyze', 'docs:refresh', 'todo:refine', 'deps:audit', 'phpstan:suggest-fixes', 'slop:scan'],
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
            ],
        ]);

        self::assertSame(['local-null-provider', 'codex', 'gemini'], array_keys($providers));
        self::assertTrue($providers['codex']->isAvailable($this->runContext($providers)));
        self::assertTrue($providers['gemini']->isAvailable($this->runContext($providers)));
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
        self::assertSame('["--sandbox","project-only"]', $result->context['stdout'] ?? null);
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
            new InMemoryStateStore(),
            new \HousekeepingAgentCron\Runtime\JsonLogger(sys_get_temp_dir() . '/agent-cron-factory-' . bin2hex(random_bytes(4)) . '.log'),
            $providers,
        );
    }
}
