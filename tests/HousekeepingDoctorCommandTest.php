<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Command\HousekeepingDoctorCommand;
use HousekeepingAgentCron\Runtime\ExitCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class HousekeepingDoctorCommandTest extends TestCase
{
    public function testDoctorCommandPassesForWritablePathsAndConfiguredProviders(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        (new Filesystem())->mkdir($dir);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
            ],
            'tasks' => [],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
                'codex' => [
                    'enabled' => true,
                    'command' => ['php', '-r', ''],
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingDoctorCommand($configFile));
            $exitCode = $tester->execute([]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('Housekeeping config looks healthy.', $display);
            self::assertStringContainsString('provider:codex', $display);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandFailsWhenEnabledProviderCommandIsMissing(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-fail-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        (new Filesystem())->mkdir($dir);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
            ],
            'tasks' => [],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
                'codex' => [
                    'enabled' => true,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingDoctorCommand($configFile));
            $exitCode = $tester->execute(['--json' => true]);

            self::assertSame(ExitCode::INVALID_CONFIG, $exitCode);
            $decoded = json_decode($tester->getDisplay(), true);
            self::assertIsArray($decoded);
            self::assertFalse($decoded['ok'] ?? true);
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertIsArray($checks[3] ?? null);
            self::assertSame('provider:codex', $checks[3]['name'] ?? null);
            self::assertSame('Enabled provider command is missing.', $checks[3]['message'] ?? null);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
