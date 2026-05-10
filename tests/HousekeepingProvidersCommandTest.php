<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Command\HousekeepingProvidersCommand;
use HousekeepingAgentCron\Runtime\ExitCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class HousekeepingProvidersCommandTest extends TestCase
{
    public function testProvidersCommandPrintsRecommendedProviderAndJson(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-providers-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $stateFile = $dir . '/state/state.json';
        (new Filesystem())->mkdir(dirname($stateFile));
        file_put_contents($stateFile, json_encode([
            'providers' => [
                'codex' => [
                    'usage' => [gmdate('Y-m-d') => 1],
                ],
            ],
        ], JSON_PRETTY_PRINT) . PHP_EOL);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $stateFile,
            ],
            'tasks' => [],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
                'codex' => [
                    'enabled' => true,
                    'daily_budget' => 10,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo json_encode(["session" => ["remaining_percent" => 70, "reset_in_seconds" => 7200]]);'],
                ],
                'gemini' => [
                    'enabled' => true,
                    'daily_budget' => 20,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => ['php', '-r', 'echo json_encode(["session" => ["remaining_percent" => 90, "reset_in_seconds" => 3600]]);'],
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingProvidersCommand($configFile));
            $exitCode = $tester->execute([]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            self::assertStringContainsString('Recommended provider: gemini', $tester->getDisplay());
            self::assertStringContainsString('External capacity', $tester->getDisplay());

            $jsonTester = new CommandTester(new HousekeepingProvidersCommand($configFile));
            $jsonExitCode = $jsonTester->execute(['--json' => true]);

            self::assertSame(ExitCode::SUCCESS, $jsonExitCode);
            $decoded = json_decode($jsonTester->getDisplay(), true);
            self::assertIsArray($decoded);
            self::assertSame('gemini', $decoded['recommended_provider'] ?? null);
            $providers = $decoded['providers'] ?? null;
            self::assertIsArray($providers);
            self::assertCount(2, $providers);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
