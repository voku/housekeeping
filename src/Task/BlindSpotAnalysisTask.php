<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class BlindSpotAnalysisTask extends AbstractProviderTask
{
    /** @var list<string> */
    private array $contextFiles;

    /**
     * @param list<string> $contextFiles
     */
    public function __construct(
        int $intervalSeconds,
        string $providerName,
        array $contextFiles = [],
    ) {
        parent::__construct($intervalSeconds, $providerName);
        $this->contextFiles = $contextFiles;
    }

    public function name(): string
    {
        return 'blindspots:analyze';
    }

    public function run(RunContext $context): TaskResult
    {
        $runs = $context->stateValue('runs');
        if (!is_array($runs) || $runs === []) {
            return TaskResult::skipped('No previous housekeeping run is available for blind-spot analysis.');
        }

        $latestRun = end($runs);
        if (!is_array($latestRun)) {
            return TaskResult::skipped('No previous housekeeping run is available for blind-spot analysis.');
        }

        $latestRunStartedAt = $latestRun['started_at'] ?? null;
        if (is_int($latestRunStartedAt)) {
            $lastAnalyzedRunStartedAt = $context->metadataValue('blind_spots.last_analyzed_run_started_at');
            if ($lastAnalyzedRunStartedAt === $latestRunStartedAt) {
                return TaskResult::skipped('Blind-spot analysis is already up to date for the latest housekeeping run.');
            }
        }

        $contextDocuments = $this->collectRepositoryFiles($context, $this->contextFiles, 'project.key_files');
        $recentRuns = $this->extractRecentRuns($runs);

        $result = $this->executeProvider(
            $context,
            'Review the previous housekeeping run, identify blind spots or missed maintenance opportunities, and suggest safe prompt or config improvements for future runs. Produce concise operational guidance only.',
            [
                ...$this->sharedMetadata($context),
                'task_state' => $context->stateValue('tasks'),
                'latest_run' => $latestRun,
                'recent_runs' => $recentRuns,
                'context_files' => $contextDocuments,
            ],
            'Blind-spot analysis completed.',
        );

        if ($result->successful && !$result->skipped && !$context->dryRun) {
            $context->setMetadataValue('blind_spots.last_analyzed_at', time());
            if (is_int($latestRunStartedAt)) {
                $context->setMetadataValue('blind_spots.last_analyzed_run_started_at', $latestRunStartedAt);
            }
            if (is_int($latestRun['exit_code'] ?? null)) {
                $context->setMetadataValue('blind_spots.last_run_exit_code', $latestRun['exit_code']);
            }
            if (is_array($latestRun['results'] ?? null)) {
                $context->setMetadataValue('blind_spots.last_run_tasks', $this->extractTaskNames($latestRun['results']));
            }

            $providerOutput = $result->context['stdout'] ?? null;
            if (is_string($providerOutput) && $providerOutput !== '') {
                $context->setMetadataValue('blind_spots.last_provider_output', $providerOutput);
            }
        }

        return $result;
    }

    /**
     * @param array<mixed, mixed> $results
     * @return list<string>
     */
    private function extractTaskNames(array $results): array
    {
        $taskNames = [];

        foreach ($results as $taskResult) {
            if (is_array($taskResult) && is_string($taskResult['task'] ?? null)) {
                $taskNames[] = $taskResult['task'];
            }
        }

        return $taskNames;
    }

    /**
     * @param array<mixed, mixed> $runs
     * @return list<array<mixed, mixed>>
     */
    private function extractRecentRuns(array $runs): array
    {
        $recentRuns = [];

        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }

            $recentRuns[] = $run;
            if (count($recentRuns) > 3) {
                array_shift($recentRuns);
            }
        }

        return $recentRuns;
    }
}
