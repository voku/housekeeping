<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

final readonly class ProviderResult
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        public bool $successful,
        public string $message,
        public array $context = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function success(string $message, array $context = []): self
    {
        return new self(true, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function failure(string $message, array $context = []): self
    {
        return new self(false, $message, $context);
    }
}
