<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\PhpstanFixSuggestionTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PhpstanFixSuggestionTaskTest extends TestCase
{
    public function testConfiguredPhpstanCommandRunsWithoutVendorBinaryPrecheck(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/agent-cron-phpstan-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($workingDirectory);

        $provider = new class implements ProviderAdapter {
            public int $calls = 0;

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
                ++$this->calls;
                $this->payload = $request->payload;

                return ProviderResult::success('Accepted.');
            }
        };
        $task = new PhpstanFixSuggestionTask(
            3600,
            'local-null-provider',
            new ProcessExecutor(),
            $workingDirectory,
            ['php', '-r', 'fwrite(STDOUT, "Found issue"); exit(1);'],
            30,
        );

        try {
            $result = $task->run(new RunContext(
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
                ['tasks' => [], 'providers' => [], 'runs' => []],
                new InMemoryStateStore(),
                new JsonLogger($workingDirectory . '/logs/housekeeping.log'),
                ['local-null-provider' => $provider],
            ));

            self::assertTrue($result->successful);
            self::assertFalse($result->skipped);
            self::assertSame('PHPStan suggestions prepared.', $result->message);
            self::assertSame(1, $provider->calls);
            self::assertIsArray($provider->payload);
            self::assertSame($workingDirectory, $provider->payload['working_directory'] ?? null);
            self::assertSame(['php', '-r', 'fwrite(STDOUT, "Found issue"); exit(1);'], $provider->payload['command'] ?? null);
            self::assertSame('Found issue', $provider->payload['analysis_output'] ?? null);
        } finally {
            (new Filesystem())->remove($workingDirectory);
        }
    }
}
