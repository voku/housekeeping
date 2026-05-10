<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Command\HousekeepingStateCommand;
use HousekeepingAgentCron\Runtime\ExitCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class HousekeepingStateCommandTest extends TestCase
{
    public function testStateCommandPrintsPersistedJson(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-state-' . bin2hex(random_bytes(4));
        $configFile = $dir . '/tasks.php';
        $stateFile = $dir . '/state/state.json';
        (new Filesystem())->mkdir(dirname($stateFile));
        file_put_contents($stateFile, json_encode([
            'tasks' => [
                'demo' => [
                    'last_message' => 'ok',
                    'url' => 'https://example.com/docs',
                    'symbol' => '☃',
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
                    'daily_budget' => 1,
                    'cooldown_seconds' => 0,
                ],
            ],
        ], true) . ';');

        try {
            $tester = new CommandTester(new HousekeepingStateCommand($configFile));
            $exitCode = $tester->execute([]);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            self::assertStringContainsString('"last_message": "ok"', $tester->getDisplay());
            self::assertStringContainsString('"url": "https://example.com/docs"', $tester->getDisplay());
            self::assertStringContainsString('"symbol": "☃"', $tester->getDisplay());
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
