<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Provider;

use HousekeepingAgentCron\Runtime\ProcessExecutor;

final readonly class OpenCodeProvider extends CliProvider
{
    /**
     * @param list<string> $command
     * @param list<string> $arguments
     */
    public function __construct(ProcessExecutor $processExecutor, array $command, array $arguments, string $workingDirectory, int $timeoutSeconds, bool $appendYolo = false)
    {
        parent::__construct($processExecutor, $command, $arguments, $workingDirectory, $timeoutSeconds, $appendYolo);
    }

    public function name(): string
    {
        return 'opencode';
    }

    protected function commandForPrompt(string $prompt): array
    {
        $command = $this->configuredCommand();
        if (!$this->hasToken($command, 'run')) {
            $command[] = 'run';
        }

        $command = [...$command, ...$this->configuredArguments()];
        $command = $this->appendTokenIfYoloConfigured($command, '--dangerously-skip-permissions');
        $command[] = $prompt;

        /** @var list<string> $command */
        return $command;
    }
}
