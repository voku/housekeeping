<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Provider\CodexProvider;
use HousekeepingAgentCron\Provider\CopilotProvider;
use HousekeepingAgentCron\Provider\GeminiProvider;
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
            [],
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
        $stdin = $decoded['stdin'] ?? null;
        self::assertIsString($stdin);
        self::assertStringContainsString('Task: docs:refresh', $stdin);
        self::assertStringContainsString('"documents"', $stdin);
    }

    public function testCliProvidersAllowConfigurableArgumentsAndOptionalYolo(): void
    {
        $provider = new CodexProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(["cwd" => getcwd(), "argv" => array_slice($argv, 1)], JSON_UNESCAPED_SLASHES);', '--'],
            ['--sandbox', 'project-only'],
            sys_get_temp_dir(),
            30,
            false,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        $stdout = $result->context['stdout'] ?? null;
        self::assertIsString($stdout);

        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        self::assertSame(sys_get_temp_dir(), $decoded['cwd'] ?? null);
        self::assertSame(['--sandbox', 'project-only'], $decoded['argv'] ?? null);
    }

    public function testGeminiProviderAppendsYoloByDefault(): void
    {
        $provider = new GeminiProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
            [],
            __DIR__,
            30,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        self::assertSame('["--yolo"]', $result->context['stdout'] ?? null);
    }

    public function testCopilotProviderAppendsYoloByDefault(): void
    {
        $provider = new CopilotProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
            [],
            __DIR__,
            30,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        self::assertSame('["--yolo"]', $result->context['stdout'] ?? null);
    }
}
