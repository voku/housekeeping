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
            ['php', '-r', 'echo json_encode(["argv" => array_slice($argv, 1), "stdin" => stream_get_contents(STDIN)], JSON_UNESCAPED_SLASHES);'],
            __DIR__,
            30,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        self::assertContains('--yolo', $result->context['command'] ?? []);
        self::assertIsString($result->context['stdout'] ?? null);

        $decoded = json_decode($result->context['stdout'], true);
        self::assertIsArray($decoded);
        self::assertSame('--yolo', $decoded['argv'][0] ?? null);
        self::assertStringContainsString('Task: docs:refresh', $decoded['stdin'] ?? '');
        self::assertStringContainsString('"documents"', $decoded['stdin'] ?? '');
    }
}
