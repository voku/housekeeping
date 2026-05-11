<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

final readonly class TaskResult
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        public bool $successful,
        public bool $skipped,
        public string $message,
        public array $context = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function success(string $message, array $context = []): self
    {
        return new self(true, false, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function failure(string $message, array $context = []): self
    {
        return new self(false, false, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function skipped(string $message, array $context = []): self
    {
        return new self(true, true, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        return new self($this->successful, $this->skipped, $this->message, [...$this->context, ...$context]);
    }
}
