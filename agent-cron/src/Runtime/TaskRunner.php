<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use HousekeepingAgentCron\Contract\HousekeepingTask;
use HousekeepingAgentCron\Contract\ProviderBackedTask;
use Throwable;

final readonly class TaskRunner
{
    /**
     * @param list<HousekeepingTask> $tasks
     */
    public function __construct(
        private array $tasks,
        private QuotaBudget $quotaBudget = new QuotaBudget(),
    ) {
    }

    public function run(RunContext $context): int
    {
        $maxTasks = $this->positiveInt($context->config['max_tasks_per_run'] ?? 1);
        $maxSeconds = $this->positiveInt($context->config['max_run_seconds'] ?? 1);
        $executed = 0;
        $exitCode = ExitCode::SUCCESS;
        $run = [
            'started_at' => $context->startedAt,
            'dry_run' => $context->dryRun,
            'task_filter' => $context->taskFilter,
            'results' => [],
        ];

        foreach ($this->tasks as $task) {
            if ($context->taskFilter !== null && $context->taskFilter !== $task->name()) {
                continue;
            }
            if ($executed >= $maxTasks) {
                break;
            }
            if ($context->elapsedSeconds() >= $maxSeconds) {
                $exitCode = ExitCode::RUNTIME_BUDGET_EXCEEDED;
                $context->logger()->log('error', 'runtime_budget_exceeded', ['max_run_seconds' => $maxSeconds]);
                break;
            }
            if (!$task->isDue($context)) {
                $this->record($context, $run, $task->name(), TaskResult::skipped('Task is not due.'));
                continue;
            }

            $providerName = null;
            if ($task instanceof ProviderBackedTask) {
                $providerName = $task->providerName();
                $providerConfig = $this->providerConfig($context, $providerName);
                if ($providerConfig === null) {
                    $result = TaskResult::failure('Provider config is missing.', ['provider' => $providerName]);
                    $this->record($context, $run, $task->name(), $result);
                    $exitCode = ExitCode::PROVIDER_UNAVAILABLE;
                    continue;
                }

                $budgetResult = $this->quotaBudget->canRun($context, $providerName, $providerConfig);
                if (!$budgetResult->successful || $budgetResult->skipped) {
                    $this->record($context, $run, $task->name(), $budgetResult);
                    if (!$budgetResult->successful) {
                        $exitCode = ExitCode::PROVIDER_UNAVAILABLE;
                    }
                    continue;
                }

                $provider = $context->provider($providerName);
                if ($provider === null || !$provider->isAvailable($context)) {
                    $result = TaskResult::failure('Provider is unavailable.', ['provider' => $providerName]);
                    $this->record($context, $run, $task->name(), $result);
                    $exitCode = ExitCode::PROVIDER_UNAVAILABLE;
                    continue;
                }
            }

            ++$executed;
            try {
                $result = $task->run($context);
            } catch (Throwable $throwable) {
                $result = TaskResult::failure($throwable->getMessage(), [
                    'exception' => $throwable::class,
                    'file' => $throwable->getFile(),
                    'line' => $throwable->getLine(),
                ]);
            }

            if ($providerName !== null && !$context->dryRun && $result->successful && !$result->skipped) {
                $this->quotaBudget->recordUse($context, $providerName);
            }
            if (!$result->successful) {
                $exitCode = ExitCode::TASK_FAILED;
            }
            $this->record($context, $run, $task->name(), $result);
        }

        $run['finished_at'] = time();
        $run['exit_code'] = $exitCode;
        $runs = $context->stateValue('runs');
        $runs = is_array($runs) ? $runs : [];
        $runs[] = $run;
        $context->setStateValue('runs', array_slice($runs, -20));
        if (!$context->dryRun) {
            $context->saveState();
        }

        return $exitCode;
    }

    /**
     * @param array<string, mixed> $run
     */
    private function record(RunContext $context, array &$run, string $taskName, TaskResult $result): void
    {
        $record = [
            'task' => $taskName,
            'successful' => $result->successful,
            'skipped' => $result->skipped,
            'message' => $result->message,
            'context' => $result->context,
            'finished_at' => time(),
        ];
        if (!isset($run['results']) || !is_array($run['results'])) {
            $run['results'] = [];
        }
        $run['results'][] = $record;
        $context->logger()->log($result->successful ? 'info' : 'error', 'task_result', $record);

        if (!$context->dryRun && !$result->skipped) {
            $context->setStateValue('tasks.' . $taskName . '.last_finished_at', $record['finished_at']);
            $context->setStateValue('tasks.' . $taskName . '.last_successful', $result->successful);
            $context->setStateValue('tasks.' . $taskName . '.last_message', $result->message);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function providerConfig(RunContext $context, string $providerName): ?array
    {
        $providers = $context->config['providers'] ?? null;
        if (!is_array($providers)) {
            return null;
        }
        $config = $providers[$providerName] ?? null;

        if (!is_array($config)) {
            return null;
        }
        /** @var array<string, mixed> $typedConfig */
        $typedConfig = $config;

        return $typedConfig;
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return 1;
    }
}
