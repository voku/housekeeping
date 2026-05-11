<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;
use HousekeepingAgentCron\Task\AbstractProviderTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final readonly class TestableProviderTask extends AbstractProviderTask
{
    public function __construct()
    {
        parent::__construct(3600, 'local-null-provider');
    }

    public function name(): string
    {
        return 'testing:task';
    }

    public function run(RunContext $context): TaskResult
    {
        return TaskResult::skipped('unused');
    }

    public function persistMetadata(RunContext $context, TaskResult $result): void
    {
        $this->persistProviderMetadata($context, 'testing', $result, 'local-null-provider');
    }
}

final class AbstractProviderTaskTest extends TestCase
{
    use StateAssertions;

    public function testPersistProviderMetadataStoresAllStructuredFields(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-provider-task-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $store = new InMemoryStateStore();
        $task = $this->task();

        try {
            $context = $this->context($dir, $store, false);
            $task->persistMetadata($context, TaskResult::success('stored', [
                'stdout' => '  stdout  ',
                'stderr' => '  stderr  ',
                'provider_output' => [
                    'summary' => 'Summary',
                    'summaries' => ['first', 'first', 'second'],
                    'patches' => [
                        [
                            'summary' => 'Patch one',
                            'path' => 'README.md',
                        ],
                        [
                            'summary' => 'Patch two',
                            'file' => 'docs/guide.md',
                            'diff_present' => true,
                        ],
                        [],
                        [
                            'summary' => null,
                            'paths' => [],
                            'diff_present' => false,
                        ],
                    ],
                    'metadata' => [
                        'source' => 'provider',
                    ],
                ],
            ]));

            self::assertSame('local-null-provider', $this->stateAt($context->state(), 'metadata.testing.last_provider'));
            self::assertIsInt($this->stateAt($context->state(), 'metadata.testing.last_recorded_at'));
            self::assertSame('stdout', $this->stateAt($context->state(), 'metadata.testing.last_stdout'));
            self::assertSame('stderr', $this->stateAt($context->state(), 'metadata.testing.last_stderr'));
            self::assertSame('Summary', $this->stateAt($context->state(), 'metadata.testing.last_summary'));
            self::assertSame(['first', 'second'], $this->stateAt($context->state(), 'metadata.testing.last_summaries'));
            $patches = $this->stateAt($context->state(), 'metadata.testing.last_patches');
            self::assertIsArray($patches);
            self::assertCount(2, $patches);
            self::assertSame(['README.md'], $this->stateAt($context->state(), 'metadata.testing.last_patches.0.paths'));
            self::assertTrue($this->stateAt($context->state(), 'metadata.testing.last_patches.1.diff_present'));
            self::assertSame(['source' => 'provider'], $this->stateAt($context->state(), 'metadata.testing.last_metadata'));
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testPersistProviderMetadataSkipsDryRunsAndFailures(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-provider-task-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $task = $this->task();

        try {
            $dryRunContext = $this->context($dir, new InMemoryStateStore(), true);
            $task->persistMetadata($dryRunContext, TaskResult::success('stored', [
                'provider_output' => ['summary' => 'Summary'],
            ]));
            self::assertNull($dryRunContext->metadataValue('testing.last_summary'));

            $failureContext = $this->context($dir, new InMemoryStateStore(), false);
            $task->persistMetadata($failureContext, TaskResult::failure('failed', [
                'provider_output' => ['summary' => 'Summary'],
            ]));
            self::assertNull($failureContext->metadataValue('testing.last_summary'));
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    private function task(): TestableProviderTask
    {
        return new TestableProviderTask();
    }

    private function context(string $dir, InMemoryStateStore $store, bool $dryRun): RunContext
    {
        return new RunContext(
            $dryRun,
            null,
            time(),
            [
                'providers' => [
                    'local-null-provider' => [
                        'enabled' => true,
                        'daily_budget' => 1,
                        'cooldown_seconds' => 0,
                    ],
                ],
            ],
            $store->load(),
            [],
            $store,
            new JsonLogger($dir . '/logs/housekeeping.log'),
            [],
        );
    }
}
