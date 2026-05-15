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
        $statePath = $dir . '/state/state.json';
        $logsPath = $dir . '/logs';
        $lockPath = $dir . '/lock';
        (new Filesystem())->mkdir($dir);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $statePath,
                'logs' => $logsPath,
                'lock' => $lockPath,
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
            self::assertStringContainsString('Check', $display);
            self::assertStringContainsString('Status', $display);
            self::assertStringContainsString('Message', $display);
            self::assertMatchesRegularExpression('/state\s+ok\s+Path is writable: ' . preg_quote(dirname($statePath), '/') . '/', $display);
            self::assertMatchesRegularExpression('/lock\s+ok\s+Path is writable: ' . preg_quote($lockPath, '/') . '/', $display);
            self::assertMatchesRegularExpression('/logs\s+ok\s+Path is writable: ' . preg_quote($logsPath, '/') . '/', $display);
            self::assertMatchesRegularExpression('/provider:codex\s+ok\s+Enabled provider command is configured\./', $display);
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

    public function testDoctorCommandFailsWhenEnabledProviderCommandContainsOnlyEmptyStrings(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-empty-command-' . bin2hex(random_bytes(4));
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
                    'command' => [''],
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingDoctorCommand($configFile));
            $exitCode = $tester->execute(['--json' => true]);

            self::assertSame(ExitCode::INVALID_CONFIG, $exitCode);
            $decoded = json_decode($tester->getDisplay(), true);
            self::assertIsArray($decoded);
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertIsArray($checks[3] ?? null);
            self::assertSame('provider:codex', $checks[3]['name'] ?? null);
            self::assertFalse($checks[3]['ok'] ?? true);
            self::assertSame('Enabled provider command is missing.', $checks[3]['message'] ?? null);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandJsonUsesPrettyPrintedUnescapedPathsAndIncludesAllEnabledProviders(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-json-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $statePath = $dir . '/state/state.json';
        $logsPath = $dir . '/logs';
        $lockPath = $dir . '/lock';
        (new Filesystem())->mkdir($dir);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $statePath,
                'logs' => $logsPath,
                'lock' => $lockPath,
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
                'gemini' => [
                    'enabled' => true,
                    'command' => ['php', '-r', ''],
                ],
                'copilot' => [
                    'command' => ['php', '-r', ''],
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingDoctorCommand($configFile));
            $exitCode = $tester->execute(['--json' => true]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringStartsWith("{\n", $display);
            self::assertStringNotContainsString('\\/', $display);
            $decoded = json_decode($display, true);
            self::assertIsArray($decoded);
            self::assertTrue($decoded['ok'] ?? false);
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertCount(5, $checks);
            self::assertIsArray($checks[0] ?? null);
            self::assertIsArray($checks[1] ?? null);
            self::assertIsArray($checks[2] ?? null);
            self::assertIsArray($checks[3] ?? null);
            self::assertIsArray($checks[4] ?? null);
            self::assertSame('Path is writable: ' . dirname($statePath), $checks[0]['message'] ?? null);
            self::assertSame('Path is writable: ' . $lockPath, $checks[1]['message'] ?? null);
            self::assertSame('Path is writable: ' . $logsPath, $checks[2]['message'] ?? null);
            self::assertSame('provider:codex', $checks[3]['name'] ?? null);
            self::assertSame('provider:gemini', $checks[4]['name'] ?? null);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandFailsForInvalidStateJson(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-invalid-state-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $statePath = $dir . '/state/state.json';
        (new Filesystem())->mkdir(dirname($statePath));
        file_put_contents($statePath, '{invalid json');
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $statePath,
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
            ],
            'tasks' => [],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingDoctorCommand($configFile));
            $exitCode = $tester->execute([]);

            self::assertSame(ExitCode::INVALID_CONFIG, $exitCode);
            self::assertStringContainsString('State file is not valid JSON: ' . $statePath, $tester->getDisplay());
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandFailsWhenConfiguredLogsPathIsAFile(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-invalid-logs-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $logsPath = $dir . '/logs-file';
        (new Filesystem())->mkdir($dir);
        file_put_contents($logsPath, 'not a directory');
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $logsPath,
                'lock' => $dir . '/lock',
            ],
            'tasks' => [],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingDoctorCommand($configFile));
            $exitCode = $tester->execute([]);

            self::assertSame(ExitCode::INVALID_CONFIG, $exitCode);
            self::assertStringContainsString('Unable to create log directory: ' . $logsPath, $tester->getDisplay());
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
