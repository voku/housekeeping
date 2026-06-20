<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Provider;

use HousekeepingAgentCron\Runtime\ProcessExecutor;

final readonly class AgyProvider extends CliProvider
{
    /**
     * @param list<string> $command
     * @param list<string> $arguments
     */
    public function __construct(ProcessExecutor $processExecutor, array $command, array $arguments, string $workingDirectory, int $timeoutSeconds, bool $appendYolo = false, ?string $model = null)
    {
        parent::__construct($processExecutor, $command, $arguments, $workingDirectory, $timeoutSeconds, $appendYolo, $model);
    }

    public function name(): string
    {
        return 'agy';
    }

    protected function commandForPrompt(string $prompt): array
    {
        $command = [...$this->configuredCommand(), ...$this->configuredArguments()];
        $command = $this->appendArgumentPairIfConfigured($command, '--model', $this->configuredModel());
        $command = $this->appendTokenIfYoloConfigured($command, '--dangerously-skip-permissions');
        if (!$this->hasToken($command, '--print', '-p', '--prompt')) {
            $command[] = '--print';
        }
        $command[] = $prompt;

        /** @var list<string> $command */
        return $command;
    }
}
