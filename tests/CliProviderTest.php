<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Provider\CodexProvider;
use HousekeepingAgentCron\Provider\ClaudeProvider;
use HousekeepingAgentCron\Provider\CopilotProvider;
use HousekeepingAgentCron\Provider\GeminiProvider;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use PHPUnit\Framework\TestCase;

final class CliProviderTest extends TestCase
{
    public function testCodexProviderUsesExecPromptArgument(): void
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
            false,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', [
            'documents' => ['README.md' => '# Snowman ☃ Docs'],
            'paths' => ['guide' => 'docs/☃-guide.md'],
        ]));

        self::assertTrue($result->successful);
        $command = $result->context['command'] ?? null;
        self::assertIsArray($command);
        self::assertNotContains('--dangerously-bypass-approvals-and-sandbox', $command);
        $stdout = $result->context['stdout'] ?? null;
        self::assertIsString($stdout);

        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        $argv = $decoded['argv'] ?? null;
        self::assertIsArray($argv);
        self::assertSame(['exec', $expectedPrompt], $argv);
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
            false,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        self::assertSame('codex', $result->context['provider'] ?? null);
        self::assertSame('finished', $result->context['stdout'] ?? null);
        self::assertSame('warning', $result->context['stderr'] ?? null);
    }

    public function testCodexProviderAllowsConfigurableArgumentsAndDangerousPermissionBypass(): void
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
            ['--sandbox', 'workspace-write'],
            sys_get_temp_dir(),
            30,
            true,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        $stdout = $result->context['stdout'] ?? null;
        self::assertIsString($stdout);

        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        self::assertSame(sys_get_temp_dir(), $decoded['cwd'] ?? null);
        self::assertSame([
            'exec',
            '--sandbox',
            'workspace-write',
            '--dangerously-bypass-approvals-and-sandbox',
            $expectedPrompt,
        ], $decoded['argv'] ?? null);
    }

    public function testGeminiProviderUsesPromptFlag(): void
    {
        $expectedPrompt = $this->expectedDocsPrompt();

        $provider = new GeminiProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
            [],
            __DIR__,
            30,
            false,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        self::assertSame(json_encode(['--prompt', $expectedPrompt], JSON_UNESCAPED_SLASHES), $result->context['stdout'] ?? null);
    }

    public function testGeminiProviderDefaultsToSafeApprovalMode(): void
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
        $stdout = $result->context['stdout'] ?? null;
        self::assertIsString($stdout);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        self::assertNotContains('--approval-mode', $decoded);
        self::assertNotContains('yolo', $decoded);
    }

    public function testCopilotProviderUsesPromptFlag(): void
    {
        $expectedPrompt = $this->expectedDocsPrompt();

        $provider = new CopilotProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
            [],
            __DIR__,
            30,
            false,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        self::assertSame(json_encode(['--prompt', $expectedPrompt], JSON_UNESCAPED_SLASHES), $result->context['stdout'] ?? null);
    }

    public function testCopilotProviderDefaultsToPromptModeWithoutYolo(): void
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
        $stdout = $result->context['stdout'] ?? null;
        self::assertIsString($stdout);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        self::assertNotContains('--yolo', $decoded);
    }

    public function testClaudeProviderUsesPrintAndPromptArgument(): void
    {
        $expectedPrompt = $this->expectedDocsPrompt();

        $provider = new ClaudeProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
            [],
            __DIR__,
            30,
            false,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        self::assertSame(json_encode(['--print', $expectedPrompt], JSON_UNESCAPED_SLASHES), $result->context['stdout'] ?? null);
    }

    public function testClaudeProviderDefaultsToPrintModeWithoutPermissionBypass(): void
    {
        $provider = new ClaudeProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
            [],
            __DIR__,
            30,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        $stdout = $result->context['stdout'] ?? null;
        self::assertIsString($stdout);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        self::assertNotContains('--dangerously-skip-permissions', $decoded);
    }

    public function testCodexProviderDefaultsToExecModeWithoutDangerousBypass(): void
    {
        $provider = new CodexProvider(
            new ProcessExecutor(),
            ['php', '-r', 'echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);', '--'],
            [],
            __DIR__,
            30,
        );

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'Sync docs with code.', ['documents' => ['README.md' => '# Docs']]));

        self::assertTrue($result->successful);
        $stdout = $result->context['stdout'] ?? null;
        self::assertIsString($stdout);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        self::assertNotContains('--dangerously-bypass-approvals-and-sandbox', $decoded);
    }

    private function expectedDocsPrompt(): string
    {
        return <<<'PROMPT'
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
    }
}
