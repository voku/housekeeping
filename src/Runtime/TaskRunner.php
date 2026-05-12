<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use HousekeepingAgentCron\Contract\HousekeepingTask;
use HousekeepingAgentCron\Contract\ProviderBackedTask;
use Symfony\Component\Console\Output\OutputInterface;
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

    public function run(RunContext $context, ?OutputInterface $output = null): int
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
                $this->writeVerbose($output, sprintf('<comment>[stop]</comment> Reached max_tasks_per_run=%d.', $maxTasks));
                break;
            }
            if ($context->elapsedSeconds() >= $maxSeconds) {
                $exitCode = ExitCode::RUNTIME_BUDGET_EXCEEDED;
                $context->logger()->log('error', 'runtime_budget_exceeded', ['max_run_seconds' => $maxSeconds]);
                $this->writeVerbose($output, sprintf('<error>[stop]</error> Runtime budget exceeded (max_run_seconds=%d).', $maxSeconds));
                break;
            }
            if (!$task->isDue($context)) {
                $result = TaskResult::skipped('Task is not due.');
                $this->record($context, $run, $task->name(), $result);
                $this->reportTaskResult($output, $task->name(), $result);
                continue;
            }

            $providerName = null;
            $routingContext = [];
            if ($task instanceof ProviderBackedTask) {
                [$providerName, $routingContext] = $this->resolveProviderName($context, $task);
                if ($providerName === null) {
                    $result = TaskResult::failure('No ready provider matched the task routing rules.', $routingContext);
                    $this->record($context, $run, $task->name(), $result);
                    $this->reportTaskResult($output, $task->name(), $result);
                    $exitCode = ExitCode::PROVIDER_UNAVAILABLE;
                    continue;
                }
                $context->setRuntimeValue('task_provider_routes.' . $task->name(), [
                    'configured_provider' => $task->providerName(),
                    'preferred_providers' => $task->preferredProviderNames(),
                    'resolved_provider' => $providerName,
                ]);
                $providerConfig = $this->providerConfig($context, $providerName);
                if ($providerConfig === null) {
                    $result = TaskResult::failure('Provider config is missing.', [
                        'provider' => $providerName,
                        ...$routingContext,
                    ]);
                    $this->record($context, $run, $task->name(), $result);
                    $this->reportTaskResult($output, $task->name(), $result);
                    $exitCode = ExitCode::PROVIDER_UNAVAILABLE;
                    continue;
                }

                $budgetResult = $this->quotaBudget->canRun($context, $providerName, $providerConfig);
                if (!$budgetResult->successful || $budgetResult->skipped) {
                    $budgetResult = $budgetResult->withContext($routingContext);
                    $this->record($context, $run, $task->name(), $budgetResult);
                    $this->reportTaskResult($output, $task->name(), $budgetResult);
                    if (!$budgetResult->successful) {
                        $exitCode = ExitCode::PROVIDER_UNAVAILABLE;
                    }
                    continue;
                }

                $provider = $context->provider($providerName);
                if ($provider === null || !$provider->isAvailable($context)) {
                    $result = TaskResult::failure('Provider is unavailable.', [
                        'provider' => $providerName,
                        ...$routingContext,
                    ]);
                    $this->record($context, $run, $task->name(), $result);
                    $this->reportTaskResult($output, $task->name(), $result);
                    $exitCode = ExitCode::PROVIDER_UNAVAILABLE;
                    continue;
                }
            }

            $this->reportTaskStart($output, $task->name(), $providerName, $routingContext);
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
            if ($providerName !== null) {
                $result = $result->withContext(['provider' => $providerName, ...$routingContext]);
            }

            if ($providerName !== null && !$context->dryRun && $result->successful && !$result->skipped) {
                $this->quotaBudget->recordUse($context, $providerName);
            }
            if (!$result->successful) {
                $exitCode = ExitCode::TASK_FAILED;
            }
            $this->record($context, $run, $task->name(), $result);
            $this->reportTaskResult($output, $task->name(), $result);
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
     * @param array<string, mixed> $routingContext
     */
    private function reportTaskStart(?OutputInterface $output, string $taskName, ?string $providerName, array $routingContext): void
    {
        if ($output === null || !$output->isVerbose()) {
            return;
        }

        $details = [];
        if ($providerName !== null) {
            $details[] = 'provider=' . $providerName;
        }
        if (is_string($routingContext['routing_reason'] ?? null)) {
            $details[] = 'routing=' . $routingContext['routing_reason'];
        }
        $readyProviders = $routingContext['ready_providers'] ?? null;
        if (is_array($readyProviders) && $readyProviders !== []) {
            $details[] = 'ready=' . implode(',', array_filter($readyProviders, static fn (mixed $provider): bool => is_string($provider) && $provider !== ''));
        }

        $suffix = $details === [] ? '' : ' (' . implode(', ', $details) . ')';
        $output->writeln(sprintf('<comment>[run]</comment> %s%s', $taskName, $suffix));
    }

    private function reportTaskResult(?OutputInterface $output, string $taskName, TaskResult $result): void
    {
        if ($output === null || !$output->isVerbose()) {
            return;
        }

        $tag = $result->successful
            ? ($result->skipped ? 'skip' : 'ok')
            : 'fail';
        $style = $result->successful
            ? ($result->skipped ? 'comment' : 'info')
            : 'error';
        $details = $this->resultDetails($result);
        $suffix = $details === [] ? '' : ' (' . implode(', ', $details) . ')';

        $output->writeln(sprintf(
            '<%s>[%s]</%s> %s: %s%s',
            $style,
            $tag,
            $style,
            $taskName,
            $result->message,
            $suffix,
        ));
    }

    /**
     * @return list<string>
     */
    private function resultDetails(TaskResult $result): array
    {
        $details = [];
        $provider = $result->context['provider'] ?? null;
        if (is_string($provider) && $provider !== '') {
            $details[] = 'provider=' . $provider;
        }
        $routingReason = $result->context['routing_reason'] ?? null;
        if (is_string($routingReason) && $routingReason !== '') {
            $details[] = 'routing=' . $routingReason;
        }
        if (array_key_exists('exit_code', $result->context)) {
            $details[] = 'exit=' . var_export($result->context['exit_code'], true);
        }
        if (($result->context['timed_out'] ?? false) === true) {
            $details[] = 'timed_out=yes';
        }

        return $details;
    }

    private function writeVerbose(?OutputInterface $output, string $message): void
    {
        if ($output !== null && $output->isVerbose()) {
            $output->writeln($message);
        }
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

    /**
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function resolveProviderName(RunContext $context, ProviderBackedTask $task): array
    {
        $configuredProvider = $task->providerName();
        $preferredProviders = $task->preferredProviderNames();
        $routingContext = [
            'configured_provider' => $configuredProvider,
            'preferred_providers' => $preferredProviders,
        ];

        if ($configuredProvider !== 'auto') {
            $routingContext['routing_reason'] = 'configured_provider';

            return [$configuredProvider, $routingContext];
        }

        $providerReports = (new ProviderCapacityInspector())->inspect($context->config, $context->state(), false, $context->startedAt);
        $readyProviders = [];
        // ProviderCapacityInspector::inspect() already returns reports in readiness order.
        foreach ($providerReports as $report) {
            if ($report->status === 'ready' || $report->status === 'ready-no-probe') {
                $readyProviders[] = $report->provider;
            }
        }
        $routingContext['ready_providers'] = $readyProviders;

        foreach ($preferredProviders as $preferredProvider) {
            if (in_array($preferredProvider, $readyProviders, true)) {
                $routingContext['routing_reason'] = 'preferred_provider';

                return [$preferredProvider, $routingContext];
            }
        }

        $recommendedProvider = $readyProviders[0] ?? null;
        if ($recommendedProvider !== null) {
            $routingContext['routing_reason'] = 'global_readiness_ranking';

            return [$recommendedProvider, $routingContext];
        }

        $routingContext['routing_reason'] = 'no_ready_provider';

        return [null, $routingContext];
    }
}
