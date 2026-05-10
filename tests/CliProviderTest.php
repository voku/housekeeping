<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Provider\CodexProvider;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use PHPUnit\Framework\TestCase;

final class CliProviderTest extends TestCase
{
    public function testCliProvidersAlwaysAppendYoloAndFormattedPrompt(): void
    {
        $provider = new CodexProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(["argv" => array_slice($argv, 1), "stdin" => stream_get_contents(STDIN)], JSON_UNESCAPED_SLASHES);', '--'],
            __DIR__,
            30,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        $command = $result->context['command'] ?? null;
        self::assertIsArray($command);
        self::assertContains('--yolo', $command);
        $stdout = $result->context['stdout'] ?? null;
        self::assertIsString($stdout);

        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        $argv = $decoded['argv'] ?? null;
        self::assertIsArray($argv);
        self::assertSame('--yolo', $argv[0] ?? null);
        $stdin = $decoded['stdin'] ?? null;
        self::assertIsString($stdin);
        self::assertStringContainsString('Task: docs:refresh', $stdin);
        self::assertStringContainsString('"documents"', $stdin);
    }
}
