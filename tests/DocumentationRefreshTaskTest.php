<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\DocumentationRefreshTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DocumentationRefreshTaskTest extends TestCase
{
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
}
