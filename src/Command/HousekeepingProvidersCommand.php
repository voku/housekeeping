<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Command;

use HousekeepingAgentCron\Runtime\ApplicationFactory;
use HousekeepingAgentCron\Runtime\ExitCode;
use HousekeepingAgentCron\Runtime\ProviderCapacityInspector;
use HousekeepingAgentCron\Runtime\ProviderCapacityReport;
use Throwable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'housekeeping:providers', description: 'Inspect provider budgets, cooldowns, and external free-resource probes.')]
final class HousekeepingProvidersCommand extends Command
{
    public function __construct(
        private readonly string $configFile,
        private readonly ApplicationFactory $factory = new ApplicationFactory(),
        private readonly ProviderCapacityInspector $inspector = new ProviderCapacityInspector(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print provider capacity details as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->factory->loadConfig($this->configFile);
            $reports = $this->inspector->inspect($config, $this->factory->stateStore($config)->load());

            if ($input->getOption('json') === true) {
                $json = json_encode([
                    'recommended_provider' => $this->recommendedProvider($reports),
                    'providers' => array_map(static fn (ProviderCapacityReport $report): array => $report->toArray(), $reports),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    $output->writeln('<error>Unable to encode provider capacity output.</error>');

                    return ExitCode::TASK_FAILED;
                }
                $output->writeln($json);

                return ExitCode::SUCCESS;
            }

            $io = new SymfonyStyle($input, $output);
            $rows = [];
            foreach ($reports as $report) {
                $rows[] = [
                    $report->provider,
                    $report->status,
                    $this->formatBudget($report),
                    $this->formatCooldown($report->cooldownRemainingSeconds),
                    $this->formatExternalCapacity($report),
                    $this->formatResetAt($report->externalResetAt),
                    $report->probeMessage ?? '-',
                ];
            }

            $io->table(['Provider', 'Status', 'Budget', 'Cooldown', 'External capacity', 'Next reset', 'Probe'], $rows);
            $recommendedProvider = $this->recommendedProvider($reports);
            if ($recommendedProvider !== null) {
                $io->success('Recommended provider: ' . $recommendedProvider);
            } else {
                $io->warning('No provider is currently ready for deterministic selection.');
            }
            $io->writeln('Sorting: status, known external free capacity, next reset, internal budget, provider name.');

            return ExitCode::SUCCESS;
        } catch (Throwable $throwable) {
            $output->writeln('<error>' . $throwable->getMessage() . '</error>');

            return ExitCode::INVALID_CONFIG;
        }
    }

    /**
     * @param list<ProviderCapacityReport> $reports
     */
    private function recommendedProvider(array $reports): ?string
    {
        if ($reports === []) {
            return null;
        }

        $status = $reports[0]->status;
        if ($status !== 'ready' && $status !== 'ready-no-probe') {
            return null;
        }

        return $reports[0]->provider;
    }

    private function formatBudget(ProviderCapacityReport $report): string
    {
        if ($report->internalBudget === null) {
            return 'unlimited';
        }

        return sprintf('%d/%d left', $report->internalBudgetRemaining ?? 0, $report->internalBudget);
    }

    private function formatCooldown(int $cooldownRemainingSeconds): string
    {
        if ($cooldownRemainingSeconds < 1) {
            return '-';
        }

        $hours = intdiv($cooldownRemainingSeconds, 3600);
        $minutes = intdiv($cooldownRemainingSeconds % 3600, 60);
        $seconds = $cooldownRemainingSeconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    private function formatExternalCapacity(ProviderCapacityReport $report): string
    {
        if ($report->externalRemainingRatio === null) {
            return '-';
        }

        return sprintf('%.1f%% free', $report->externalRemainingRatio * 100);
    }

    private function formatResetAt(?int $resetAt): string
    {
        if ($resetAt === null) {
            return '-';
        }

        return gmdate('Y-m-d H:i:s', $resetAt) . ' UTC';
    }
}
