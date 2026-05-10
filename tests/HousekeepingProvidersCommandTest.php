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
    /**
     * @return list<string>
     */
    private function resourceCommand(string $label, int $remainingPercent, int $resetInSeconds): array
    {
        return [
            PHP_BINARY,
            '-r',
            sprintf(
                'echo json_encode(["session" => ["label" => "%s", "remaining_percent" => %d, "reset_in_seconds" => %d]], JSON_UNESCAPED_UNICODE);',
                $label,
                $remainingPercent,
                $resetInSeconds,
            ),
        ];
    }

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
                    'resource_command' => $this->resourceCommand('grüße codex', 70, 7200),
                ],
                'gemini' => [
                    'enabled' => true,
                    'daily_budget' => 20,
                    'cooldown_seconds' => 0,
                    'working_directory' => __DIR__,
                    'resource_command' => $this->resourceCommand('grüße gemini', 90, 3600),
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingProvidersCommand($configFile));
            $exitCode = $tester->execute([]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('Recommended provider: gemini', $display);
            self::assertStringContainsString('External capacity', $display);
            self::assertMatchesRegularExpression('/\bProvider\s+Status\s+Budget\b/', $display);
            self::assertMatchesRegularExpression('/\bgemini\s+ready\s+20\/20 left\b/', $display);
            self::assertMatchesRegularExpression('/\bcodex\s+ready\s+9\/10 left\b/', $display);

            $jsonTester = new CommandTester(new HousekeepingProvidersCommand($configFile));
            $jsonExitCode = $jsonTester->execute(['--json' => true]);

            self::assertSame(ExitCode::SUCCESS, $jsonExitCode);
            $jsonDisplay = $jsonTester->getDisplay();
            self::assertStringContainsString("\n    \"providers\": [\n", $jsonDisplay);
            self::assertStringContainsString(PHP_BINARY, $jsonDisplay);
            self::assertStringContainsString('grüße gemini', $jsonDisplay);
            self::assertStringNotContainsString('\\/', $jsonDisplay);
            self::assertStringNotContainsString('\u00fc', $jsonDisplay);

            $decoded = json_decode($jsonDisplay, true);
            self::assertIsArray($decoded);
            self::assertSame('gemini', $decoded['recommended_provider'] ?? null);
            $providers = $decoded['providers'] ?? null;
            self::assertIsArray($providers);
            self::assertCount(2, $providers);
            self::assertIsArray($providers[0] ?? null);
            $firstProvider = $providers[0];
            self::assertSame('gemini', $firstProvider['provider'] ?? null);
            self::assertIsArray($firstProvider['probe_command'] ?? null);
            self::assertSame(PHP_BINARY, $firstProvider['probe_command'][0] ?? null);
            self::assertIsArray($firstProvider['external_metrics'] ?? null);
            self::assertIsArray($firstProvider['external_metrics'][0] ?? null);
            self::assertSame('grüße gemini', $firstProvider['external_metrics'][0]['label'] ?? null);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
