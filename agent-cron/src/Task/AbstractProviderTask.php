<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Contract\ProviderBackedTask;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

abstract readonly class AbstractProviderTask implements ProviderBackedTask
{
    public function __construct(
        private int $intervalSeconds,
        private string $providerName,
    ) {
    }

    public function providerName(): string
    {
        return $this->providerName;
    }

    public function isDue(RunContext $context): bool
    {
        if ($context->taskFilter === $this->name()) {
            return true;
        }

        $lastRun = $context->stateValue('tasks.' . $this->name() . '.last_finished_at');
        if (!is_int($lastRun)) {
            return true;
        }

        return time() - $lastRun >= $this->intervalSeconds;
    }

    /**
     * @param array<string, mixed> $payload
     */
    final protected function executeProvider(RunContext $context, string $prompt, array $payload, string $successMessage): TaskResult
    {
        if ($context->dryRun) {
            return TaskResult::skipped(sprintf('Dry-run: %s was not sent to a provider.', $this->name()));
        }

        $provider = $context->provider($this->providerName);
        if ($provider === null) {
            return TaskResult::failure('Configured provider is not registered.', ['provider' => $this->providerName]);
        }

        $result = $provider->execute(new ProviderRequest($this->name(), $prompt, $payload));
        if (!$result->successful) {
            return TaskResult::failure($result->message, $result->context);
        }

        return TaskResult::success($successMessage, $result->context);
    }
}
