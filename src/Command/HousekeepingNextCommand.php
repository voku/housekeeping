<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Command;

use HousekeepingAgentCron\Contract\ProviderBackedTask;
use HousekeepingAgentCron\Runtime\ApplicationFactory;
use HousekeepingAgentCron\Runtime\ExitCode;
use HousekeepingAgentCron\Runtime\RunContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'housekeeping:next', description: 'Show due and upcoming housekeeping tasks.')]
final class HousekeepingNextCommand extends Command
{
    public function __construct(
        private readonly string $configFile,
        private readonly ApplicationFactory $factory = new ApplicationFactory(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print task schedule details as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->factory->loadConfig($this->configFile);
            $stateStore = $this->factory->stateStore($config);
            $startedAt = time();
            $context = new RunContext(
                false,
                null,
                $startedAt,
                $config,
                $stateStore->load(),
                [],
                $stateStore,
                $this->factory->logger($config),
                [],
            );

            $tasks = [];
            foreach ($this->factory->tasks($config) as $task) {
                $taskConfig = $this->taskConfig($config, $task->name());
                $intervalSeconds = $this->positiveInt($taskConfig['interval_seconds'] ?? 3600, 3600);
                $lastFinishedAt = $context->stateValue('tasks.' . $task->name() . '.last_finished_at');
                $lastFinishedAt = is_int($lastFinishedAt) ? $lastFinishedAt : null;
                $due = $task->isDue($context);
                $nextDueAt = $lastFinishedAt === null ? null : $lastFinishedAt + $intervalSeconds;

                $tasks[] = [
                    'name' => $task->name(),
                    'provider' => $task instanceof ProviderBackedTask ? $task->providerName() : '-',
                    'priority' => $this->intValue($taskConfig['priority'] ?? 0),
                    'interval_seconds' => $intervalSeconds,
                    'last_finished_at' => $lastFinishedAt,
                    'due' => $due,
                    'seconds_until_due' => $due || $nextDueAt === null ? 0 : max($nextDueAt - $startedAt, 0),
                    'next_due_at' => $due ? null : $nextDueAt,
                ];
            }

            $recommendedTask = $this->recommendedTask($tasks);
            if ($input->getOption('json') === true) {
                $json = json_encode([
                    'recommended_task' => $recommendedTask['name'] ?? null,
                    'recommended_reason' => ($recommendedTask['due'] ?? false) === true ? 'due_now' : 'next_up',
                    'tasks' => $tasks,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    $output->writeln('<error>Unable to encode task schedule output.</error>');

                    return ExitCode::TASK_FAILED;
                }
                $output->writeln($json);

                return ExitCode::SUCCESS;
            }

            $rows = [];
            foreach ($tasks as $task) {
                $rows[] = [
                    $task['name'],
                    $task['provider'],
                    $task['due'] === true ? 'due now' : 'scheduled',
                    $this->formatTimestamp($task['last_finished_at']),
                    $this->formatNextRun($task['due'] === true, $task['seconds_until_due'], $task['next_due_at']),
                ];
            }

            $io = new SymfonyStyle($input, $output);
            $io->table(['Task', 'Provider', 'Status', 'Last finished', 'Next run'], $rows);
            if ($recommendedTask === null) {
                $io->warning('No enabled housekeeping tasks are configured.');
            } elseif ($recommendedTask['due'] === true) {
                $io->success('Next due task: ' . $recommendedTask['name']);
            } else {
                $io->writeln(sprintf(
                    'Next scheduled task: %s in %d s.',
                    (string) $recommendedTask['name'],
                    $recommendedTask['seconds_until_due'],
                ));
            }

            return ExitCode::SUCCESS;
        } catch (Throwable $throwable) {
            $output->writeln('<error>' . $throwable->getMessage() . '</error>');

            return ExitCode::INVALID_CONFIG;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function taskConfig(array $config, string $taskName): array
    {
        $tasks = $config['tasks'] ?? null;
        if (!is_array($tasks)) {
            return [];
        }

        $taskConfig = $tasks[$taskName] ?? null;
        if (!is_array($taskConfig)) {
            return [];
        }
        /** @var array<string, mixed> $typedTaskConfig */
        $typedTaskConfig = $taskConfig;

        return $typedTaskConfig;
    }

    /**
     * @param list<array{name: string, provider: string, priority: int, interval_seconds: int, last_finished_at: int|null, due: bool, seconds_until_due: int, next_due_at: int|null}> $tasks
     * @return array{name: string, provider: string, priority: int, interval_seconds: int, last_finished_at: int|null, due: bool, seconds_until_due: int, next_due_at: int|null}|null
     */
    private function recommendedTask(array $tasks): ?array
    {
        foreach ($tasks as $task) {
            if ($task['due'] === true) {
                return $task;
            }
        }

        if ($tasks === []) {
            return null;
        }

        usort($tasks, static function (array $left, array $right): int {
            if ($left['seconds_until_due'] === $right['seconds_until_due']) {
                if ($left['priority'] === $right['priority']) {
                    return strcmp($left['name'], $right['name']);
                }

                return $right['priority'] <=> $left['priority'];
            }

            return $left['seconds_until_due'] <=> $right['seconds_until_due'];
        });

        return $tasks[0];
    }

    private function positiveInt(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function intValue(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private function formatTimestamp(?int $timestamp): string
    {
        if ($timestamp === null) {
            return '-';
        }

        return gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
    }

    private function formatNextRun(bool $due, int $secondsUntilDue, ?int $nextDueAt): string
    {
        if ($due) {
            return 'now';
        }
        if ($nextDueAt === null) {
            return 'now';
        }

        return sprintf('%s UTC (in %d s)', gmdate('Y-m-d H:i:s', $nextDueAt), $secondsUntilDue);
    }
}
