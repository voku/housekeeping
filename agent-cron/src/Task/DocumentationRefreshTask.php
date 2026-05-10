<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Contract\ProviderBackedTask;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class DocumentationRefreshTask implements ProviderBackedTask
{
    public function __construct(
        private int $intervalSeconds,
        private string $providerName,
    ) {
    }

    public function name(): string
    {
        return 'docs:refresh';
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

    public function run(RunContext $context): TaskResult
    {
        if ($context->dryRun) {
            return TaskResult::skipped('Dry-run: documentation refresh request was not sent to a provider.');
        }

        $provider = $context->provider($this->providerName);
        if ($provider === null) {
            return TaskResult::failure('Configured provider is not registered.', ['provider' => $this->providerName]);
        }

        $result = $provider->execute(new ProviderRequest(
            $this->name(),
            'Review repository documentation and TODO notes. Return a concise report with safe patch suggestions only.',
            ['mode' => 'patch-suggestion-only'],
        ));

        if (!$result->successful) {
            return TaskResult::failure($result->message, $result->context);
        }

        return TaskResult::success('Documentation refresh completed.', $result->context);
    }
}
