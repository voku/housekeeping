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

                return ProviderResult::success('Accepted.', ['stdout' => 'Prefer adding QUICKSTART.md to blind-spot context.']);
            }
        };

        $store = new InMemoryStateStore([
            'tasks' => [],
            'providers' => [],
            'runs' => [
                [
                    'started_at' => 1700000000,
                    'finished_at' => 1700000010,
                    'exit_code' => 0,
                    'results' => [
                        ['task' => 'project:discover', 'successful' => true],
                        ['task' => 'docs:refresh', 'successful' => true],
                    ],
                ],
            ],
            'metadata' => [
                'project' => [
                    'key_files' => ['README.md'],
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
            self::assertSame(1700000000, $latestRun['started_at'] ?? null);
            $learningMetadata = $provider->payload['learning_metadata'] ?? null;
            self::assertIsArray($learningMetadata);
            self::assertSame('Keep prompts conservative.', $learningMetadata['last_provider_output'] ?? null);
            self::assertSame('Prefer adding QUICKSTART.md to blind-spot context.', $this->stateAt($context->state(), 'metadata.blind_spots.last_provider_output'));
            self::assertIsInt($this->stateAt($context->state(), 'metadata.blind_spots.last_analyzed_at'));
            self::assertSame(1700000000, $this->stateAt($context->state(), 'metadata.blind_spots.last_analyzed_run_started_at'));
            self::assertSame(['project:discover', 'docs:refresh'], $this->stateAt($context->state(), 'metadata.blind_spots.last_run_tasks'));
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
}
