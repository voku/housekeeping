<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

final readonly class ProcessResult
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        public array $command,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut,
        public string $workingDirectory,
        public ?string $exceptionMessage = null,
    ) {
    }

    public function successful(): bool
    {
        return !$this->timedOut && $this->exceptionMessage === null && $this->exitCode === 0;
    }

    public function combinedOutput(): string
    {
        return trim($this->stdout . PHP_EOL . $this->stderr);
    }
}
