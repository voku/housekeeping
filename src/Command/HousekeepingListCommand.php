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
    use ReadsTaskConfig;

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
                    'tasks' => array_map(fn ($task): array => [
                        'name' => $task->name(),
                        'provider' => $task instanceof ProviderBackedTask ? $task->providerName() : '-',
                        'interval_seconds' => $this->positiveInt($this->taskConfig($config, $task->name())['interval_seconds'] ?? 3600, 3600),
                        'priority' => $this->intValue($this->taskConfig($config, $task->name())['priority'] ?? 0),
                    ], $tasks),
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
}
