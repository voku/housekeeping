<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Provider;

use HousekeepingAgentCron\Runtime\ProcessExecutor;

final readonly class CodexProvider extends CliProvider
{
    /**
     * @param list<string> $command
     * @param list<string> $arguments
     */
    public function __construct(ProcessExecutor $processExecutor, array $command, array $arguments, string $workingDirectory, int $timeoutSeconds, bool $appendYolo = true)
    {
        parent::__construct($processExecutor, $command, $arguments, $workingDirectory, $timeoutSeconds, $appendYolo);
    }

    public function name(): string
    {
        return 'codex';
    }

    protected function commandForPrompt(string $prompt): array
    {
        $command = $this->configuredCommand();
        if (!$this->hasToken($command, 'exec')) {
            $command[] = 'exec';
        }

        $command = [...$command, ...$this->configuredArguments()];
        $command = $this->appendTokenIfYoloConfigured($command, '--dangerously-bypass-approvals-and-sandbox');
        $command[] = $prompt;

        /** @var list<string> $command */
        return $command;
    }
}
