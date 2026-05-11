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
    public function testCodexProviderUsesExecPromptArgumentAndYolo(): void
    {
        $expectedPrompt = <<<'PROMPT'
You are an autonomous housekeeping coding agent running from cron.

Never run `git commit`, create commits, or otherwise mutate git history yourself; only return patch suggestions or uncommitted file changes for human review.

Task: docs:refresh

Goal: Sync docs with code.

Payload:
{
    "documents": {
        "README.md": "# Snowman ☃ Docs"
    },
    "paths": {
        "guide": "docs/☃-guide.md"
    }
}
PROMPT;

        $provider = new CodexProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(["argv" => array_slice($argv, 1), "stdin" => stream_get_contents(STDIN)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);', '--'],
            [],
            __DIR__,
            30,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', [
            'documents' => ['README.md' => '# Snowman ☃ Docs'],
            'paths' => ['guide' => 'docs/☃-guide.md'],
        ]));

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
        self::assertSame(['exec', '--yolo', $expectedPrompt], $argv);
        $stdin = $decoded['stdin'] ?? null;
        self::assertIsString($stdin);
        self::assertSame('', $stdin);
        self::assertSame($expectedPrompt, $result->context['prompt'] ?? null);
    }

    public function testCliProvidersReturnProviderMetadataAndTrimOutputStreams(): void
    {
        $provider = new CodexProvider(
            new ProcessExecutor(),
            ['php', '-r', 'fwrite(STDOUT, "  finished\\n"); fwrite(STDERR, "  warning\\n");', '--'],
            [],
            __DIR__,
            30,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        self::assertSame('codex', $result->context['provider'] ?? null);
        self::assertSame('finished', $result->context['stdout'] ?? null);
        self::assertSame('warning', $result->context['stderr'] ?? null);
    }

    public function testCliProvidersAllowConfigurableArgumentsAndOptionalYolo(): void
    {
        $expectedPrompt = <<<'PROMPT'
You are an autonomous housekeeping coding agent running from cron.

Never run `git commit`, create commits, or otherwise mutate git history yourself; only return patch suggestions or uncommitted file changes for human review.

Task: docs:refresh

Goal: Sync docs with code.

Payload:
{
    "documents": {
        "README.md": "# Docs"
    }
}
PROMPT;

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
        self::assertSame(['exec', '--sandbox', 'project-only', $expectedPrompt], $decoded['argv'] ?? null);
    }

    public function testGeminiProviderUsesGenerateAndPromptFlag(): void
    {
        $expectedPrompt = <<<'PROMPT'
You are an autonomous housekeeping coding agent running from cron.

Never run `git commit`, create commits, or otherwise mutate git history yourself; only return patch suggestions or uncommitted file changes for human review.

Task: docs:refresh

Goal: Sync docs with code.

Payload:
{
    "documents": {
        "README.md": "# Docs"
    }
}
PROMPT;

        $provider = new GeminiProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
            [],
            __DIR__,
            30,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        self::assertSame(json_encode(['generate', '--prompt', $expectedPrompt], JSON_UNESCAPED_SLASHES), $result->context['stdout'] ?? null);
    }

    public function testCopilotProviderUsesSuggestAndPromptFlag(): void
    {
        $expectedPrompt = <<<'PROMPT'
You are an autonomous housekeeping coding agent running from cron.

Never run `git commit`, create commits, or otherwise mutate git history yourself; only return patch suggestions or uncommitted file changes for human review.

Task: docs:refresh

Goal: Sync docs with code.

Payload:
{
    "documents": {
        "README.md": "# Docs"
    }
}
PROMPT;

        $provider = new CopilotProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
            [],
            __DIR__,
            30,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        self::assertSame(json_encode(['suggest', '--prompt', $expectedPrompt], JSON_UNESCAPED_SLASHES), $result->context['stdout'] ?? null);
    }
}
