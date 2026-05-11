<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Provider;

use HousekeepingAgentCron\Runtime\ProcessExecutor;

final readonly class CopilotProvider extends CliProvider
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
        return 'copilot';
    }

    protected function commandForPrompt(string $prompt): array
    {
        $command = [...$this->configuredCommand(), ...$this->configuredArguments()];
        $command = $this->appendTokenIfYoloConfigured($command, '--yolo');
        if (!$this->hasToken($command, '--prompt', '-p', '--interactive', '-i')) {
            $command[] = '--prompt';
            $command[] = $prompt;
        }

        /** @var list<string> $command */
        return $command;
    }
}
