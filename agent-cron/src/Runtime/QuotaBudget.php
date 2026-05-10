<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

final class QuotaBudget
{
    /**
     * @param array<string, mixed> $providerConfig
     */
    public function canRun(RunContext $context, string $providerName, array $providerConfig): TaskResult
    {
        if (($providerConfig['enabled'] ?? false) !== true) {
            return TaskResult::failure('Provider is disabled.', ['provider' => $providerName]);
        }

        $today = gmdate('Y-m-d');
        $budget = $this->positiveInt($providerConfig['daily_budget'] ?? 0);
        $used = $context->stateValue('providers.' . $providerName . '.usage.' . $today);
        $used = is_int($used) ? $used : 0;
        if ($budget > 0 && $used >= $budget) {
            return TaskResult::failure('Provider daily budget is exhausted.', [
                'provider' => $providerName,
                'daily_budget' => $budget,
                'used' => $used,
            ]);
        }

        $cooldown = $this->positiveInt($providerConfig['cooldown_seconds'] ?? 0);
        $lastUsed = $context->stateValue('providers.' . $providerName . '.last_used_at');
        if ($cooldown > 0 && is_int($lastUsed) && time() - $lastUsed < $cooldown) {
            return TaskResult::skipped('Provider cooldown is active.', [
                'provider' => $providerName,
                'cooldown_seconds' => $cooldown,
                'last_used_at' => $lastUsed,
            ]);
        }

        return TaskResult::success('Provider budget allows execution.');
    }

    public function recordUse(RunContext $context, string $providerName): void
    {
        $today = gmdate('Y-m-d');
        $used = $context->stateValue('providers.' . $providerName . '.usage.' . $today);
        $context->setStateValue('providers.' . $providerName . '.usage.' . $today, is_int($used) ? $used + 1 : 1);
        $context->setStateValue('providers.' . $providerName . '.last_used_at', time());
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return 0;
    }
}
