<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Command;

use HousekeepingAgentCron\Contract\ProviderBackedTask;
use HousekeepingAgentCron\Runtime\ApplicationFactory;
use HousekeepingAgentCron\Runtime\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'housekeeping:list', description: 'List registered housekeeping tasks.')]
final class HousekeepingListCommand extends Command
{
    public function __construct(
        private readonly string $configFile,
        private readonly ApplicationFactory $factory = new ApplicationFactory(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print configured tasks as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->factory->loadConfig($this->configFile);
            $tasks = $this->factory->tasks($config);
            if ($input->getOption('json') === true) {
                $json = json_encode([
                    'tasks' => array_map(function ($task) use ($config): array {
                        $taskConfig = $this->taskConfig($config, $task->name());

                        return [
                            'name' => $task->name(),
                            'provider' => $task instanceof ProviderBackedTask ? $task->providerName() : '-',
                            'interval_seconds' => $this->positiveInt($taskConfig['interval_seconds'] ?? 3600, 3600),
                            'priority' => $this->intValue($taskConfig['priority'] ?? 0),
                        ];
                    }, $tasks),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    $output->writeln('<error>Unable to encode task list output.</error>');

                    return ExitCode::TASK_FAILED;
                }
                $output->writeln($json);

                return ExitCode::SUCCESS;
            }

            $rows = [];
            foreach ($tasks as $task) {
                $rows[] = [
                    $task->name(),
                    $task instanceof ProviderBackedTask ? $task->providerName() : '-',
                ];
            }
            (new SymfonyStyle($input, $output))->table(['Task', 'Provider'], $rows);

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

    private function positiveInt(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function intValue(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
