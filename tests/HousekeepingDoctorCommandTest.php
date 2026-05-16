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

    public function testDoctorCommandFailsWhenConfiguredTaskFilesAreMissing(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-missing-task-files-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $repositoryRoot = $dir . '/repo';
        (new Filesystem())->mkdir([$dir, $repositoryRoot]);
        file_put_contents($repositoryRoot . '/README.md', '# Docs');
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
                'repository_root' => $repositoryRoot,
            ],
            'tasks' => [
                'docs:refresh' => [
                    'enabled' => true,
                    'input_files' => ['README.md'],
                    'context_files' => ['MISSING.md'],
                ],
            ],
            'providers' => [
                'local-null-provider' => [
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
            self::assertContains(
                [
                    'name' => 'task:docs:refresh:files',
                    'ok' => false,
                    'message' => 'Missing configured input/context files: context_files=' . $repositoryRoot . '/MISSING.md',
                ],
                $checks,
            );
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandIgnoresTaskFileChecksWhenTaskIsNotExplicitlyEnabled(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-disabled-task-files-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $repositoryRoot = $dir . '/repo';
        (new Filesystem())->mkdir([$dir, $repositoryRoot]);
        file_put_contents($repositoryRoot . '/README.md', '# Docs');
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
                'repository_root' => $repositoryRoot,
            ],
            'tasks' => [
                'docs:refresh' => [
                    'input_files' => ['README.md'],
                    'context_files' => ['MISSING.md'],
                ],
            ],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingDoctorCommand($configFile));
            $exitCode = $tester->execute(['--json' => true]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            $decoded = json_decode($tester->getDisplay(), true);
            self::assertIsArray($decoded);
            self::assertTrue($decoded['ok'] ?? false);
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertNotContains('task:docs:refresh:files', array_column($checks, 'name'));
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandContinuesPastDisabledTasksBeforeCheckingLaterEnabledOnes(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-later-enabled-task-files-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $repositoryRoot = $dir . '/repo';
        (new Filesystem())->mkdir([$dir, $repositoryRoot]);
        file_put_contents($repositoryRoot . '/README.md', '# Docs');
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
                'repository_root' => $repositoryRoot,
            ],
            'tasks' => [
                'docs:refresh' => [
                    'input_files' => ['README.md'],
                ],
                'todo:refine' => [
                    'enabled' => true,
                    'input_files' => ['MISSING-TODO.md'],
                ],
            ],
            'providers' => [
                'local-null-provider' => [
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
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertContains(
                [
                    'name' => 'task:todo:refine:files',
                    'ok' => false,
                    'message' => 'Missing configured input/context files: input_files=' . $repositoryRoot . '/MISSING-TODO.md',
                ],
                $checks,
            );
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandFailsWhenConfiguredTaskInputFilesAreMissing(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-missing-task-input-files-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $repositoryRoot = $dir . '/repo';
        (new Filesystem())->mkdir([$dir, $repositoryRoot]);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
                'repository_root' => $repositoryRoot,
            ],
            'tasks' => [
                'docs:refresh' => [
                    'enabled' => true,
                    'input_files' => ['README.md'],
                ],
            ],
            'providers' => [
                'local-null-provider' => [
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
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertContains(
                [
                    'name' => 'task:docs:refresh:files',
                    'ok' => false,
                    'message' => 'Missing configured input/context files: input_files=' . $repositoryRoot . '/README.md',
                ],
                $checks,
            );
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandJsonIncludesEveryTaskFileCheck(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-task-file-check-count-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $repositoryRoot = $dir . '/repo';
        (new Filesystem())->mkdir([$dir, $repositoryRoot]);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
                'repository_root' => $repositoryRoot,
            ],
            'tasks' => [
                'docs:refresh' => [
                    'enabled' => true,
                    'input_files' => ['README.md'],
                ],
                'todo:refine' => [
                    'enabled' => true,
                    'input_files' => ['TODO.md'],
                ],
            ],
            'providers' => [
                'local-null-provider' => [
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
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertContains('task:docs:refresh:files', array_column($checks, 'name'));
            self::assertContains('task:todo:refine:files', array_column($checks, 'name'));
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandNormalizesRepositoryRootWithoutDuplicateSlashes(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-normalized-task-paths-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $repositoryRoot = $dir . '/repo/';
        (new Filesystem())->mkdir([$dir, $repositoryRoot]);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
                'repository_root' => $repositoryRoot,
            ],
            'tasks' => [
                'docs:refresh' => [
                    'enabled' => true,
                    'input_files' => ['MISSING.md'],
                ],
            ],
            'providers' => [
                'local-null-provider' => [
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
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertContains(
                [
                    'name' => 'task:docs:refresh:files',
                    'ok' => false,
                    'message' => 'Missing configured input/context files: input_files=' . rtrim($repositoryRoot, '/') . '/MISSING.md',
                ],
                $checks,
            );
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandUsesPackageRootWhenRepositoryRootIsNotConfigured(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-default-repository-root-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        (new Filesystem())->mkdir($dir);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $dir . '/logs',
                'lock' => $dir . '/lock',
            ],
            'tasks' => [
                'docs:refresh' => [
                    'enabled' => true,
                    'input_files' => ['README.md'],
                ],
            ],
            'providers' => [
                'local-null-provider' => [
                    'enabled' => true,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingDoctorCommand($configFile));
            $exitCode = $tester->execute(['--json' => true]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            $decoded = json_decode($tester->getDisplay(), true);
            self::assertIsArray($decoded);
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertContains(
                [
                    'name' => 'task:docs:refresh:files',
                    'ok' => true,
                    'message' => 'Configured input/context files exist.',
                ],
                $checks,
            );
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
                'copilot' => [
                    'command' => ['php', '-r', ''],
                ],
                'gemini' => [
                    'enabled' => true,
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

    public function testDoctorCommandMarksReadOnlyLockDirectoryAsNotWritable(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-read-only-lock-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $lockPath = $dir . '/lock';
        (new Filesystem())->mkdir([$dir, $lockPath]);
        chmod($lockPath, 0555);
        file_put_contents($configFile, '<?php return ' . var_export([
            'paths' => [
                'state' => $dir . '/state/state.json',
                'logs' => $dir . '/logs',
                'lock' => $lockPath,
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
            $exitCode = $tester->execute(['--json' => true]);

            self::assertSame(ExitCode::INVALID_CONFIG, $exitCode);
            $decoded = json_decode($tester->getDisplay(), true);
            self::assertIsArray($decoded);
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertIsArray($checks[1] ?? null);
            self::assertFalse($checks[1]['ok'] ?? true);
            self::assertSame('Path is not writable: ' . $lockPath, $checks[1]['message'] ?? null);
        } finally {
            chmod($lockPath, 0775);
            (new Filesystem())->remove($dir);
        }
    }

    public function testDoctorCommandSurfacesPathCheckFilesystemExceptions(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-doctor-mkdir-failure-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $stateDirectory = $dir . '/state';
        $statePath = $stateDirectory . '/state.json';
        (new Filesystem())->mkdir($dir);
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
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem
            ->method('mkdir')
            ->willReturnCallback(static function (string|iterable $dirs) use ($stateDirectory): void {
                $paths = is_iterable($dirs) ? iterator_to_array($dirs) : [$dirs];
                foreach ($paths as $path) {
                    if (!is_string($path)) {
                        continue;
                    }
                    if ($path === $stateDirectory) {
                        throw new \RuntimeException('state mkdir failed');
                    }
                    if (!is_dir($path)) {
                        mkdir($path, 0775, true);
                    }
                }
            });

        try {
            $tester = new CommandTester(new HousekeepingDoctorCommand($configFile, filesystem: $filesystem));
            $exitCode = $tester->execute(['--json' => true]);

            self::assertSame(ExitCode::INVALID_CONFIG, $exitCode);
            $decoded = json_decode($tester->getDisplay(), true);
            self::assertIsArray($decoded);
            $checks = $decoded['checks'] ?? null;
            self::assertIsArray($checks);
            self::assertIsArray($checks[0] ?? null);
            self::assertFalse($checks[0]['ok'] ?? true);
            self::assertSame('state mkdir failed', $checks[0]['message'] ?? null);
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
