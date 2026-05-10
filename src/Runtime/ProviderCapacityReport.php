<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

final readonly class ProviderCapacityReport
{
    /**
     * @param list<string>|null $probeCommand
     * @param array<int, array{label: string, remaining_ratio: float, reset_at: int|null}> $externalMetrics
     */
    public function __construct(
        public string $provider,
        public bool $enabled,
        public string $status,
        public ?int $internalBudget,
        public int $internalUsed,
        public ?int $internalBudgetRemaining,
        public int $cooldownRemainingSeconds,
        public ?float $externalRemainingRatio,
        public ?int $externalResetAt,
        public ?array $probeCommand,
        public ?string $probeMessage,
        public array $externalMetrics,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'enabled' => $this->enabled,
            'status' => $this->status,
            'internal_budget' => $this->internalBudget,
            'internal_used' => $this->internalUsed,
            'internal_budget_remaining' => $this->internalBudgetRemaining,
            'cooldown_remaining_seconds' => $this->cooldownRemainingSeconds,
            'external_remaining_ratio' => $this->externalRemainingRatio,
            'external_reset_at' => $this->externalResetAt,
            'probe_command' => $this->probeCommand,
            'probe_message' => $this->probeMessage,
            'external_metrics' => $this->externalMetrics,
        ];
    }
}
