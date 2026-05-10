<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Provider;

use HousekeepingAgentCron\Runtime\ProcessExecutor;

final readonly class GeminiProvider extends CliProvider
{
    /**
     * @param list<string> $command
     */
    public function __construct(ProcessExecutor $processExecutor, array $command, string $workingDirectory, int $timeoutSeconds)
    {
        parent::__construct($processExecutor, $command, $workingDirectory, $timeoutSeconds);
    }

    public function name(): string
    {
        return 'gemini';
    }
}
