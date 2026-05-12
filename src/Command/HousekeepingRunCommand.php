<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Command;

use HousekeepingAgentCron\Contract\HousekeepingTask;
use HousekeepingAgentCron\Runtime\ApplicationFactory;
use HousekeepingAgentCron\Runtime\ExitCode;
use HousekeepingAgentCron\Runtime\RepositoryOwnerRerunner;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskRunner;
use HousekeepingAgentCron\Runtime\TimestampedConsoleOutput;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Throwable;

#[AsCommand(name: 'housekeeping:run', description: 'Run due housekeeping tasks once.')]
final class HousekeepingRunCommand extends Command
{
    public function __construct(
        private readonly string $configFile,
        private readonly ApplicationFactory $factory = new ApplicationFactory(),
        private readonly RepositoryOwnerRerunner $repositoryOwnerRerunner = new RepositoryOwnerRerunner(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would run without invoking providers or persisting state.');
        $this->addOption('task', null, InputOption::VALUE_REQUIRED, 'Run only one task by name.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->factory->loadConfig($this->configFile);
            $tasks = $this->factory->tasks($config);
            $taskFilter = $this->taskOption($input, $tasks);
            $rerunExitCode = $this->repositoryOwnerRerunner->maybeRerun(
                $this->launcherPath(),
                $this->configFile,
                $config,
                (bool) $input->getOption('dry-run'),
                $taskFilter,
                $output,
            );
            if ($rerunExitCode !== null) {
                return $rerunExitCode;
            }
            $lockDir = $this->factory->lockDir($config);
            (new Filesystem())->mkdir($lockDir);
            $lock = (new LockFactory(new FlockStore($lockDir)))->createLock('housekeeping-run', 1.0, false);
            if (!$lock->acquire()) {
                TimestampedConsoleOutput::write($output, '<comment>Another housekeeping run is already active.</comment>');

                return ExitCode::LOCK_HELD;
            }

            try {
                $stateStore = $this->factory->stateStore($config);
                $logger = $this->factory->logger($config);
                $context = new RunContext(
                    (bool) $input->getOption('dry-run'),
                    $taskFilter,
                    time(),
                    $config,
                    $stateStore->load(),
                    [],
                    $stateStore,
                    $logger,
                    $this->factory->providers($config),
                );
                $logger->log('info', 'run_started', [
                    'dry_run' => $context->dryRun,
                    'task_filter' => $context->taskFilter,
                ]);
                if ($output->isVerbose()) {
                    TimestampedConsoleOutput::write($output, sprintf(
                        '<comment>[run]</comment> config=%s repository_root=%s dry_run=%s%s',
                        $this->configFile,
                        $context->repositoryRoot(),
                        $context->dryRun ? 'yes' : 'no',
                        $context->taskFilter !== null ? ' task=' . $context->taskFilter : '',
                    ));
                }
                $exitCode = (new TaskRunner($tasks))->run($context, $output);
                $logger->log($exitCode === ExitCode::SUCCESS ? 'info' : 'error', 'run_finished', ['exit_code' => $exitCode]);
                TimestampedConsoleOutput::write($output, $exitCode === ExitCode::SUCCESS ? '<info>Housekeeping run completed.</info>' : '<error>Housekeeping run completed with errors.</error>');

                return $exitCode;
            } finally {
                $lock->release();
            }
        } catch (Throwable $throwable) {
            TimestampedConsoleOutput::write($output, '<error>' . $throwable->getMessage() . '</error>');

            return $throwable instanceof RuntimeException ? ExitCode::INVALID_CONFIG : ExitCode::TASK_FAILED;
        }
    }

    private function launcherPath(): string
    {
        return dirname(__DIR__, 2) . '/bin/agent-cron';
    }

    /**
     * @param list<HousekeepingTask> $tasks
     */
    private function taskOption(InputInterface $input, array $tasks): ?string
    {
        $task = $input->getOption('task');
        if ($task === null) {
            return null;
        }
        if (!is_string($task) || $task === '') {
            throw new RuntimeException('The --task option must be a non-empty task name.');
        }
        $knownTasks = array_map(static fn (HousekeepingTask $configuredTask): string => $configuredTask->name(), $tasks);
        if (!in_array($task, $knownTasks, true)) {
            throw new RuntimeException('Unknown task configured for --task: ' . $task);
        }

        return $task;
    }
}
