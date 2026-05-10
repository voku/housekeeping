<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

final readonly class ProviderRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $taskName,
        public string $prompt,
        public array $payload = [],
    ) {
    }
}
