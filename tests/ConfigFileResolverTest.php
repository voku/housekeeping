<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\ConfigFileResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigFileResolverTest extends TestCase
{
    public function testResolverUsesDefaultConfigWhenNoOverrideIsPresent(): void
    {
        $result = (new ConfigFileResolver())->resolve(
            ['bin/agent-cron', 'housekeeping:run'],
            '/default/tasks.php',
        );

        self::assertSame('/default/tasks.php', $result['config_file']);
        self::assertSame(['bin/agent-cron', 'housekeeping:run'], $result['argv']);
    }

    public function testResolverUsesEnvironmentConfigWhenPresent(): void
    {
        $result = (new ConfigFileResolver())->resolve(
            ['bin/agent-cron', 'housekeeping:list'],
            '/default/tasks.php',
            '/env/project.php',
        );

        self::assertSame('/env/project.php', $result['config_file']);
        self::assertSame(['bin/agent-cron', 'housekeeping:list'], $result['argv']);
    }

    public function testResolverLetsCliConfigOverrideEnvironment(): void
    {
        $result = (new ConfigFileResolver())->resolve(
            ['bin/agent-cron', '--config=/cron/project.php', 'housekeeping:run'],
            '/default/tasks.php',
            '/env/project.php',
        );

        self::assertSame('/cron/project.php', $result['config_file']);
        self::assertSame(['bin/agent-cron', 'housekeeping:run'], $result['argv']);
    }

    public function testResolverSupportsSeparateConfigArgument(): void
    {
        $result = (new ConfigFileResolver())->resolve(
            ['bin/agent-cron', '--config', '/cron/project.php', 'housekeeping:providers'],
            '/default/tasks.php',
        );

        self::assertSame('/cron/project.php', $result['config_file']);
        self::assertSame(['bin/agent-cron', 'housekeeping:providers'], $result['argv']);
    }

    public function testResolverRejectsMissingConfigValue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The --config option requires a non-empty file path.');

        (new ConfigFileResolver())->resolve(
            ['bin/agent-cron', '--config'],
            '/default/tasks.php',
        );
    }
}
