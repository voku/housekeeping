<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\SelectedFilesMaintenanceTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SelectedFilesMaintenanceTaskTest extends TestCase
{
    public function testSelectedFilesMaintenanceSkipsWhenNoFilesAreSelected(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-selected-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);

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
            $task = new SelectedFilesMaintenanceTask(
                'phpdocs:refresh',
                3600,
                'local-null-provider',
                new ProcessExecutor(),
                $dir,
                ['php', '-r', ''],
                'Prompt',
                'Done.',
            );

            $result = $task->run($this->context($dir, $provider));

            self::assertTrue($result->successful);
            self::assertTrue($result->skipped);
            self::assertSame('No candidate files were selected.', $result->message);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testSelectedFilesMaintenanceIsSkippedWhenProviderDoesNotChangeFiles(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-selected-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $file = $dir . '/Example.php';
        file_put_contents($file, "<?php\n");

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
            $task = new SelectedFilesMaintenanceTask(
                'phpdocs:refresh',
                3600,
                'local-null-provider',
                new ProcessExecutor(),
                $dir,
                ['php', '-r', 'fwrite(STDOUT, "Example.php\n");'],
                'Prompt',
                'Done.',
            );

            $result = $task->run($this->context($dir, $provider));

            self::assertTrue($result->successful);
            self::assertTrue($result->skipped);
            self::assertSame('phpdocs:refresh produced no file changes.', $result->message);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testSelectedFilesMaintenanceReportsChangedFiles(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-selected-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $file = $dir . '/Example.php';
        file_put_contents($file, "<?php\n");

        $provider = new class ($file) implements ProviderAdapter {
            public function __construct(private readonly string $file)
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
                file_put_contents($this->file, "<?php\n/** updated */\n");

                return ProviderResult::success('Accepted.');
            }
        };

        try {
            $task = new SelectedFilesMaintenanceTask(
                'phpdocs:refresh',
                3600,
                'local-null-provider',
                new ProcessExecutor(),
                $dir,
                ['php', '-r', 'fwrite(STDOUT, "Example.php\n");'],
                'Prompt',
                'Done.',
            );

            $result = $task->run($this->context($dir, $provider));

            self::assertTrue($result->successful);
            self::assertFalse($result->skipped);
            self::assertSame(['Example.php'], $result->context['changed_files'] ?? null);
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
