<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\BlindSpotAnalysisTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BlindSpotAnalysisTaskTest extends TestCase
{
    use StateAssertions;

    public function testBlindSpotAnalysisForwardsLatestRunAndStoresMetadata(): void
    {
        $repositoryRoot = sys_get_temp_dir() . '/agent-cron-blind-spots-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($repositoryRoot);
        file_put_contents($repositoryRoot . '/README.md', '# Project');

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

                return ProviderResult::success('Accepted.', [
                    'provider_output' => [
                        'summary' => 'Structured blind-spot summary.',
                    ],
                    'stdout' => 'Prefer adding QUICKSTART.md to blind-spot context.',
                ]);
            }
        };

        $store = new InMemoryStateStore([
            'tasks' => [],
            'providers' => [],
            'runs' => [
                [
                    'started_at' => 1699999980,
                    'finished_at' => 1699999990,
                    'exit_code' => 0,
                    'results' => [
                        ['task' => 'todo:refine', 'successful' => true],
                    ],
                ],
                [
                    'started_at' => 1700000000,
                    'finished_at' => 1700000010,
                    'exit_code' => 0,
                    'results' => [
                        ['task' => 'project:discover', 'successful' => true],
                        ['task' => 'docs:refresh', 'successful' => true],
                    ],
                ],
                'ignore-me',
                [
                    'started_at' => 1700000020,
                    'finished_at' => 1700000030,
                    'exit_code' => 0,
                    'results' => [
                        ['task' => 'commits:learn', 'successful' => true],
                    ],
                ],
                [
                    'started_at' => 1700000040,
                    'finished_at' => 1700000050,
                    'exit_code' => 0,
                    'results' => [
                        ['task' => 'blindspots:analyze', 'successful' => true],
                        ['task' => 'docs:refresh', 'successful' => true],
                    ],
                ],
            ],
            'metadata' => [
                'project' => [
                    'key_files' => ['README.md'],
                    'repository_root' => $repositoryRoot,
                ],
                'learning' => [
                    'last_provider_output' => 'Keep prompts conservative.',
                ],
            ],
        ]);

        $context = new RunContext(
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
                        'daily_budget' => 10,
                        'cooldown_seconds' => 0,
                    ],
                ],
            ],
            $store->load(),
            [],
            $store,
            new JsonLogger($repositoryRoot . '/var/logs/housekeeping.log'),
            ['local-null-provider' => $provider],
        );

        try {
            $result = (new BlindSpotAnalysisTask(3600, 'local-null-provider', [$repositoryRoot . '/README.md']))->run($context);

            self::assertTrue($result->successful);
            self::assertIsArray($provider->payload);
            $latestRun = $provider->payload['latest_run'] ?? null;
            self::assertIsArray($latestRun);
            self::assertSame(1700000040, $latestRun['started_at'] ?? null);
            $projectMetadata = $provider->payload['project_metadata'] ?? null;
            self::assertIsArray($projectMetadata);
            self::assertSame($repositoryRoot, $projectMetadata['repository_root'] ?? null);
            $learningMetadata = $provider->payload['learning_metadata'] ?? null;
            self::assertIsArray($learningMetadata);
            self::assertSame('Keep prompts conservative.', $learningMetadata['last_provider_output'] ?? null);
            $recentRuns = $provider->payload['recent_runs'] ?? null;
            self::assertIsArray($recentRuns);
            self::assertCount(3, $recentRuns);
            self::assertSame([1700000000, 1700000020, 1700000040], array_column($recentRuns, 'started_at'));
            self::assertSame('Structured blind-spot summary.', $this->stateAt($context->state(), 'metadata.blind_spots.last_provider_output'));
            self::assertSame('Structured blind-spot summary.', $this->stateAt($context->state(), 'metadata.blind_spots.last_summary'));
            self::assertIsInt($this->stateAt($context->state(), 'metadata.blind_spots.last_analyzed_at'));
            self::assertSame(1700000040, $this->stateAt($context->state(), 'metadata.blind_spots.last_analyzed_run_started_at'));
            self::assertSame(0, $this->stateAt($context->state(), 'metadata.blind_spots.last_run_exit_code'));
            self::assertSame(['blindspots:analyze', 'docs:refresh'], $this->stateAt($context->state(), 'metadata.blind_spots.last_run_tasks'));
        } finally {
            (new Filesystem())->remove($repositoryRoot);
        }
    }

    public function testBlindSpotAnalysisSkipsWhenLatestRunWasAlreadyAnalyzed(): void
    {
        $repositoryRoot = sys_get_temp_dir() . '/agent-cron-blind-spots-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($repositoryRoot);

        $context = new RunContext(
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
                        'daily_budget' => 10,
                        'cooldown_seconds' => 0,
                    ],
                ],
            ],
            [
                'tasks' => [],
                'providers' => [],
                'runs' => [
                    [
                        'started_at' => 1700000000,
                        'results' => [],
                    ],
                ],
                'metadata' => [
                    'blind_spots' => [
                        'last_analyzed_run_started_at' => 1700000000,
                    ],
                ],
            ],
            [],
            new InMemoryStateStore(),
            new JsonLogger($repositoryRoot . '/var/logs/housekeeping.log'),
            [],
        );

        try {
            $result = (new BlindSpotAnalysisTask(3600, 'local-null-provider'))->run($context);

            self::assertTrue($result->successful);
            self::assertTrue($result->skipped);
            self::assertSame('Blind-spot analysis is already up to date for the latest housekeeping run.', $result->message);
        } finally {
            (new Filesystem())->remove($repositoryRoot);
        }
    }

    public function testBlindSpotAnalysisDoesNotPersistMetadataWhenProviderFails(): void
    {
        $repositoryRoot = sys_get_temp_dir() . '/agent-cron-blind-spots-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($repositoryRoot);

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
                return ProviderResult::failure('No analysis available.');
            }
        };

        $context = new RunContext(
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
                        'daily_budget' => 10,
                        'cooldown_seconds' => 0,
                    ],
                ],
            ],
            [
                'tasks' => [],
                'providers' => [],
                'runs' => [
                    [
                        'started_at' => 1700000000,
                        'exit_code' => 0,
                        'results' => [],
                    ],
                ],
            ],
            [],
            new InMemoryStateStore(),
            new JsonLogger($repositoryRoot . '/var/logs/housekeeping.log'),
            ['local-null-provider' => $provider],
        );

        try {
            $result = (new BlindSpotAnalysisTask(3600, 'local-null-provider'))->run($context);

            self::assertFalse($result->successful);
            self::assertNull($context->metadataValue('blind_spots.last_analyzed_at'));
            self::assertNull($context->metadataValue('blind_spots.last_run_exit_code'));
        } finally {
            (new Filesystem())->remove($repositoryRoot);
        }
    }
}
