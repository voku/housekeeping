<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Command;

use HousekeepingAgentCron\Contract\ProviderBackedTask;
use HousekeepingAgentCron\Runtime\ApplicationFactory;
use HousekeepingAgentCron\Runtime\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->factory->loadConfig($this->configFile);
            $rows = [];
            foreach ($this->factory->tasks($config) as $task) {
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
