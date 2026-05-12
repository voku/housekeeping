<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;
use HousekeepingAgentCron\Task\AbstractProviderTask;
use HousekeepingAgentCron\Task\DocumentationRefreshTask;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DocumentationRefreshTaskTest extends TestCase
{
    use StateAssertions;

    public function testDocumentationInputsAreForwardedToProvider(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-docs-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $firstFile = $dir . '/README.md';
        $secondFile = $dir . '/TODO.md';
        file_put_contents($firstFile, 'Readme contents');
        file_put_contents($secondFile, 'Todo contents');

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
            $result = (new DocumentationRefreshTask(3600, 'local-null-provider', [$firstFile, $secondFile]))->run(new RunContext(
                false,
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
                [
                    'tasks' => [],
                    'providers' => [],
                    'runs' => [],
                    'metadata' => [
                        'project' => [
                            'repository_root' => $dir,
                        ],
                        'learning' => [
                            'last_provider_output' => 'Keep docs in sync with releases.',
                        ],
                        'blind_spots' => [
                            'last_provider_output' => 'Quick start drifted from the default config.',
                        ],
                    ],
                ],
                [],
                new InMemoryStateStore(),
                new JsonLogger($dir . '/logs/housekeeping.log'),
                ['local-null-provider' => $provider],
            ));

            self::assertTrue($result->successful);
            self::assertIsArray($provider->payload);
            self::assertIsArray($provider->payload['documents'] ?? null);
            self::assertSame([
                $firstFile => 'Readme contents',
                $secondFile => 'Todo contents',
            ], $provider->payload['documents']);
            $projectMetadata = $provider->payload['project_metadata'] ?? null;
            self::assertIsArray($projectMetadata);
            self::assertSame($dir, $projectMetadata['repository_root'] ?? null);
            $blindSpotMetadata = $provider->payload['blind_spot_metadata'] ?? null;
            self::assertIsArray($blindSpotMetadata);
            self::assertSame(
                'Quick start drifted from the default config.',
                $blindSpotMetadata['last_provider_output'] ?? null,
            );
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDocumentationRefreshIgnoresDiscoveredProjectDocsOutsideConfiguredInputs(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-docs-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $firstFile = $dir . '/README.md';
        $ignoredFile = $dir . '/CHANGELOG.md';
        file_put_contents($firstFile, 'Readme contents');
        file_put_contents($ignoredFile, 'Ignored changelog contents');

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
            $result = (new DocumentationRefreshTask(3600, 'local-null-provider', [$firstFile]))->run(new RunContext(
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
                            'repository_root' => $dir,
                            'documentation_files' => ['README.md', 'CHANGELOG.md'],
                        ],
                    ],
                ],
                [],
                new InMemoryStateStore(),
                new JsonLogger($dir . '/logs/housekeeping.log'),
                ['local-null-provider' => $provider],
            ));

            self::assertTrue($result->successful);
            self::assertSame(['README.md' => 'Readme contents'], $provider->payload['documents'] ?? null);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDocumentationRefreshPersistsNormalizedProviderSummaryAndPatchMetadata(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-docs-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $document = $dir . '/README.md';
        file_put_contents($document, 'Readme contents');

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
                $stdout = json_encode([
                    'summary' => 'Refresh the README examples.',
                    'patches' => [
                        [
                            'summary' => 'Update the quick-start snippet.',
                            'paths' => ['docs/guide.md', 'README.md'],
                            'path' => 'README.md',
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                Assert::assertIsString($stdout);

                return ProviderResult::success('Accepted.', ['stdout' => $stdout]);
            }
        };
        $store = new InMemoryStateStore();

        try {
            $context = new RunContext(
                false,
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
                ['local-null-provider' => $provider],
            );

            $result = (new DocumentationRefreshTask(3600, 'local-null-provider', [$document]))->run($context);

            self::assertTrue($result->successful);
            self::assertSame('Refresh the README examples.', $this->stateAt($context->state(), 'metadata.task_provider_results.docs:refresh.last_summary'));
            self::assertSame('Update the quick-start snippet.', $this->stateAt($context->state(), 'metadata.task_provider_results.docs:refresh.last_patches.0.summary'));
            self::assertSame(
                ['docs/guide.md', 'README.md'],
                $this->stateAt($context->state(), 'metadata.task_provider_results.docs:refresh.last_patches.0.paths'),
            );
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testPersistProviderMetadataKeepsSingularPathField(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-docs-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($dir);
        $store = new InMemoryStateStore();
        $task = new readonly class extends AbstractProviderTask {
            public function __construct()
            {
                parent::__construct(3600, 'local-null-provider');
            }

            public function name(): string
            {
                return 'docs:refresh';
            }

            public function run(RunContext $context): TaskResult
            {
                return TaskResult::skipped('not used');
            }

            public function persist(RunContext $context, TaskResult $result): void
            {
                $this->persistProviderMetadata($context, 'task_provider_results.docs:refresh', $result, 'local-null-provider');
            }
        };

        try {
            $context = new RunContext(
                false,
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

            $task->persist($context, TaskResult::success('Stored.', [
                'provider_output' => [
                    'patches' => [
                        [
                            'summary' => 'Sync README section.',
                            'path' => 'README.md',
                            'diff_present' => true,
                        ],
                    ],
                ],
            ]));

            self::assertSame(
                ['README.md'],
                $this->stateAt($context->state(), 'metadata.task_provider_results.docs:refresh.last_patches.0.paths'),
            );
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
