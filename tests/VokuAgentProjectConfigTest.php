<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VokuAgentProjectConfigTest extends TestCase
{
    public function testConfigUsesAutoModeAndAddsPhpTasksForAgentPackage(): void
    {
        $projectRoot = sys_get_temp_dir() . '/agent-learning-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/vendor/bin', 0777, true);
        file_put_contents($projectRoot . '/README.md', '# Agent learning');
        file_put_contents($projectRoot . '/composer.json', '{}');
        file_put_contents($projectRoot . '/phpstan.neon.dist', 'parameters:');
        file_put_contents($projectRoot . '/vendor/bin/phpstan', '#!/usr/bin/env php');

        $previousProject = getenv('HOUSEKEEPING_AGENT_PROJECT');
        $previousRoot = getenv('HOUSEKEEPING_AGENT_PROJECT_ROOT');
        putenv('HOUSEKEEPING_AGENT_PROJECT=agent-learning');
        putenv('HOUSEKEEPING_AGENT_PROJECT_ROOT=' . $projectRoot);

        try {
            $config = require __DIR__ . '/../config/voku-agent-project.php';

            self::assertIsArray($config);
            $paths = $config['paths'] ?? null;
            $tasks = $config['tasks'] ?? null;
            $providers = $config['providers'] ?? null;
            self::assertIsArray($paths);
            self::assertIsArray($tasks);
            self::assertIsArray($providers);

            $docsTask = $tasks['docs:refresh'] ?? null;
            $claude = $providers['claude'] ?? null;
            $agy = $providers['agy'] ?? null;
            self::assertIsArray($docsTask);
            self::assertIsArray($claude);
            self::assertIsArray($agy);

            self::assertSame($projectRoot, $paths['repository_root'] ?? null);
            self::assertSame('auto', $docsTask['provider'] ?? null);
            self::assertSame(['claude', 'agy', 'gemini', 'copilot', 'codex'], $docsTask['preferred_providers'] ?? null);
            self::assertArrayHasKey('phpdocs:refresh', $tasks);
            self::assertArrayHasKey('phpstan:suggest-fixes', $tasks);
            self::assertTrue($claude['append_yolo'] ?? false);
            self::assertTrue($agy['append_yolo'] ?? false);
        } finally {
            $this->restoreEnvironment('HOUSEKEEPING_AGENT_PROJECT', $previousProject);
            $this->restoreEnvironment('HOUSEKEEPING_AGENT_PROJECT_ROOT', $previousRoot);
            unlink($projectRoot . '/vendor/bin/phpstan');
            unlink($projectRoot . '/phpstan.neon.dist');
            unlink($projectRoot . '/composer.json');
            unlink($projectRoot . '/README.md');
            rmdir($projectRoot . '/vendor/bin');
            rmdir($projectRoot . '/vendor');
            rmdir($projectRoot);
        }
    }

    public function testConfigRejectsNonAgentProjectName(): void
    {
        $previousProject = getenv('HOUSEKEEPING_AGENT_PROJECT');
        putenv('HOUSEKEEPING_AGENT_PROJECT=IT-Portal');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('HOUSEKEEPING_AGENT_PROJECT must be an agent-* repository name.');

            require __DIR__ . '/../config/voku-agent-project.php';
        } finally {
            $this->restoreEnvironment('HOUSEKEEPING_AGENT_PROJECT', $previousProject);
        }
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
    }
}
