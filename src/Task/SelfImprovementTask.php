<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use FilesystemIterator;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProcessResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class SelfImprovementTask extends AbstractProviderTask
{
    /**
     * @param list<string> $scopePaths
     * @param list<string> $contextFiles
     * @param list<list<string>> $validationCommands
     * @param list<string> $preferredProviderNames
     */
    public function __construct(
        int $intervalSeconds,
        string $providerName,
        private ProcessExecutor $processExecutor,
        private string $workingDirectory,
        private array $scopePaths = [],
        private array $contextFiles = [],
        private array $validationCommands = [],
        private int $runThreshold = 10,
        private int $recentRunLimit = 10,
        private int $logEntryLimit = 60,
        private int $timeoutSeconds = 1200,
        array $preferredProviderNames = [],
    ) {
        parent::__construct($intervalSeconds, $providerName, $preferredProviderNames);
    }

    public function name(): string
    {
        return 'self-improve:housekeeping';
    }

    public function isDue(RunContext $context): bool
    {
        if ($context->taskFilter === $this->name()) {
            return true;
        }
        if (!parent::isDue($context)) {
            return false;
        }

        return count($this->reviewWindowRuns($context)) >= $this->runThreshold;
    }

    public function run(RunContext $context): TaskResult
    {
        $forceRun = $context->taskFilter === $this->name();
        $reviewWindowRuns = $this->reviewWindowRuns($context);
        if (!$forceRun && count($reviewWindowRuns) < $this->runThreshold) {
            return TaskResult::skipped(sprintf(
                'Self-improvement threshold not reached yet (%d/%d runs).',
                count($reviewWindowRuns),
                $this->runThreshold,
            ));
        }
        if ($reviewWindowRuns === []) {
            return TaskResult::skipped('No completed housekeeping runs are available for self-improvement.');
        }

        $recentRuns = array_slice($reviewWindowRuns, -$this->recentRunLimit);
        $latestRun = $reviewWindowRuns[array_key_last($reviewWindowRuns)] ?? null;
        if ($latestRun === null) {
            return TaskResult::skipped('No completed housekeeping runs are available for self-improvement.');
        }

        $earliestReviewTimestamp = null;
        foreach ($reviewWindowRuns as $run) {
            $startedAt = $run['started_at'] ?? null;
            if (is_int($startedAt) && ($earliestReviewTimestamp === null || $startedAt < $earliestReviewTimestamp)) {
                $earliestReviewTimestamp = $startedAt;
            }
        }

        $scopeBefore = $this->scopeFiles();
        $snapshotBefore = $this->snapshotContents($scopeBefore);
        $contextDocuments = $this->configuredRepositoryFiles($context, $this->contextFiles);

        $result = $this->executeProvider(
            $context,
            'Review the recent housekeeping runs and housekeeping log history, then apply at most one small self-improvement inside the allowed scope paths only. Prefer reducing repeated timeouts, validation gaps, payload bloat, flaky routing, or other issues already visible in the logs. Keep changes backward compatible, stay inside the allowed scope, add or update focused tests when behavior changes, and avoid broad rewrites. The wrapper will automatically run php -l on changed PHP files plus the supplied validation and smoke commands, and will revert your changes if any check fails.',
            [
                'working_directory' => $this->workingDirectory,
                'allowed_scope_paths' => $this->scopePaths,
                'validation_commands' => $this->validationCommands,
                'task_state' => $context->stateValue('tasks'),
                'recent_runs' => array_map(fn (array $run): array => $this->runSummary($run), $recentRuns),
                'failure_summary' => $this->failureSummary($recentRuns),
                'recent_log_entries' => $this->recentLogEntries($context, $earliestReviewTimestamp),
                'context_files' => $contextDocuments,
                ...$this->sharedMetadata($context),
            ],
            'Self-improvement completed.',
        );

        $latestRunStartedAt = $latestRun['started_at'] ?? null;
        if ($result->skipped || $context->dryRun) {
            return $result;
        }

        if (!$result->successful) {
            $this->markReviewed($context, $latestRunStartedAt, count($reviewWindowRuns));

            return $result;
        }

        $scopeAfter = $this->scopeFiles();
        $changedFiles = $this->changedFiles($snapshotBefore, $scopeAfter);
        if ($changedFiles === []) {
            $finalResult = TaskResult::success('Self-improvement review completed without code changes.', $result->context);
            $this->markReviewed($context, $latestRunStartedAt, count($reviewWindowRuns), []);
            $this->persistProviderMetadata($context, 'self_improvement', $finalResult);

            return $finalResult;
        }

        $validationResults = $this->runValidation($scopeAfter);
        $failedValidation = $this->firstFailedValidation($validationResults);
        if ($failedValidation !== null) {
            $this->restoreScope($snapshotBefore, $scopeBefore, $scopeAfter);
            $this->markReviewed($context, $latestRunStartedAt, count($reviewWindowRuns), []);
            $finalResult = TaskResult::success(
                'Self-improvement proposal failed validation and was reverted.',
                [
                    ...$result->context,
                    'changed_files' => $changedFiles,
                    'restored_files' => $changedFiles,
                    'validation_results' => $validationResults,
                    'failed_validation' => $failedValidation,
                    'reverted' => true,
                ],
            );
            $this->persistProviderMetadata($context, 'self_improvement', $finalResult);

            return $finalResult;
        }

        $finalResult = $result->withContext([
            'changed_files' => $changedFiles,
            'validation_results' => $validationResults,
        ]);
        $this->markReviewed($context, $latestRunStartedAt, count($reviewWindowRuns), $changedFiles);
        $this->persistProviderMetadata($context, 'self_improvement', $finalResult);

        return $finalResult;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewWindowRuns(RunContext $context): array
    {
        $runs = $context->stateValue('runs');
        if (!is_array($runs)) {
            return [];
        }

        $lastReviewedRunStartedAt = $context->metadataValue('self_improvement.last_reviewed_run_started_at');
        $reviewWindowRuns = [];
        foreach ($runs as $run) {
            if (!$this->isStringMap($run) || ($run['dry_run'] ?? false) === true) {
                continue;
            }

            $startedAt = $run['started_at'] ?? null;
            if (!is_int($startedAt)) {
                continue;
            }
            if (is_int($lastReviewedRunStartedAt) && $startedAt <= $lastReviewedRunStartedAt) {
                continue;
            }

            $reviewWindowRuns[] = $run;
        }

        return $reviewWindowRuns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentLogEntries(RunContext $context, ?int $sinceTimestamp): array
    {
        $paths = $context->config['paths'] ?? null;
        if (!$this->isStringMap($paths)) {
            return [];
        }

        $logDir = $paths['logs'] ?? null;
        if (!is_string($logDir) || $logDir === '') {
            return [];
        }

        $logFile = rtrim($logDir, '/') . '/housekeeping.log';
        if (!is_file($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!$this->isStringMap($decoded)) {
                continue;
            }

            $timestampValue = $decoded['ts'] ?? '';
            if (!is_scalar($timestampValue)) {
                continue;
            }

            $timestamp = strtotime((string) $timestampValue);
            if ($sinceTimestamp !== null && $timestamp !== false && $timestamp < $sinceTimestamp) {
                continue;
            }

            $entry = [
                'ts' => $decoded['ts'] ?? null,
                'level' => $decoded['level'] ?? null,
                'event' => $decoded['event'] ?? null,
            ];
            $decodedContext = $decoded['context'] ?? null;
            if (is_array($decodedContext)) {
                $contextSummary = [];
                foreach (['task', 'message', 'finished_at', 'exit_code', 'max_run_seconds'] as $key) {
                    if (array_key_exists($key, $decodedContext)) {
                        $contextSummary[$key] = $decodedContext[$key];
                    }
                }

                $taskContext = $decodedContext['context'] ?? null;
                if (is_array($taskContext)) {
                    foreach (['provider', 'configured_provider', 'routing_reason', 'timed_out'] as $key) {
                        if (array_key_exists($key, $taskContext)) {
                            $contextSummary[$key] = $taskContext[$key];
                        }
                    }
                }

                if ($contextSummary !== []) {
                    $entry['context'] = $contextSummary;
                }
            }

            $entries[] = $entry;
        }

        return array_slice($entries, -$this->logEntryLimit);
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @return list<array<string, mixed>>
     */
    private function failureSummary(array $runs): array
    {
        $failures = [];
        foreach ($runs as $run) {
            $results = $run['results'] ?? null;
            if (!is_array($results)) {
                continue;
            }

            foreach ($results as $result) {
                if (!is_array($result) || ($result['successful'] ?? true) === true) {
                    continue;
                }

                $task = is_string($result['task'] ?? null) ? $result['task'] : 'unknown';
                $message = is_string($result['message'] ?? null) ? $result['message'] : 'Unknown failure';
                $key = $task . '|' . $message;
                if (!isset($failures[$key])) {
                    $failures[$key] = [
                        'task' => $task,
                        'message' => $message,
                        'count' => 0,
                    ];
                }
                ++$failures[$key]['count'];
            }
        }

        return array_values($failures);
    }

    /**
     * @param array<string, string> $filesBefore
     * @param list<string> $scopeAfter
     * @return list<string>
     */
    private function changedFiles(array $filesBefore, array $scopeAfter): array
    {
        $filesAfter = $this->snapshotContents($scopeAfter);
        $paths = array_values(array_unique([...array_keys($filesBefore), ...array_keys($filesAfter)]));
        $changedFiles = [];

        foreach ($paths as $path) {
            if (($filesBefore[$path] ?? null) === ($filesAfter[$path] ?? null)) {
                continue;
            }

            $changedFiles[] = $this->displayWorkingPath($path);
        }

        sort($changedFiles);

        return $changedFiles;
    }

    /**
     * @param list<string> $scopeAfter
     * @return list<array<string, mixed>>
     */
    private function runValidation(array $scopeAfter): array
    {
        $results = [];
        foreach ($this->changedPhpFiles($scopeAfter) as $path) {
            $results[] = $this->processResultToArray($this->processExecutor->execute([PHP_BINARY, '-l', $path], $this->workingDirectory, 60));
        }

        foreach ($this->validationCommands as $command) {
            $results[] = $this->processResultToArray($this->processExecutor->execute($command, $this->workingDirectory, $this->timeoutSeconds));
        }

        return $results;
    }

    /**
     * @param list<array<string, mixed>> $validationResults
     * @return array<string, mixed>|null
     */
    private function firstFailedValidation(array $validationResults): ?array
    {
        foreach ($validationResults as $validationResult) {
            if (($validationResult['successful'] ?? false) !== true) {
                return $validationResult;
            }
        }

        return null;
    }

    /**
     * @param list<string> $scopeBefore
     * @param list<string> $scopeAfter
     * @param array<string, string> $filesBefore
     */
    private function restoreScope(array $filesBefore, array $scopeBefore, array $scopeAfter): void
    {
        foreach ($filesBefore as $path => $contents) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            file_put_contents($path, $contents);
        }

        foreach (array_diff($scopeAfter, $scopeBefore) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function scopeFiles(): array
    {
        $files = [];
        foreach ($this->scopePaths as $scopePath) {
            $absolutePath = $this->absoluteWorkingPath($scopePath);
            if (is_file($absolutePath)) {
                $files[] = $absolutePath;
                continue;
            }
            if (!is_dir($absolutePath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) {
                if ($fileInfo instanceof SplFileInfo && $fileInfo->isFile()) {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $paths
     * @return array<string, string>
     */
    private function snapshotContents(array $paths): array
    {
        $contents = [];
        foreach ($paths as $path) {
            $fileContents = file_get_contents($path);
            if ($fileContents === false) {
                continue;
            }

            $contents[$path] = $fileContents;
        }

        ksort($contents);

        return $contents;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function changedPhpFiles(array $paths): array
    {
        $phpFiles = [];
        foreach ($paths as $path) {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                $phpFiles[] = $path;
            }
        }

        return $phpFiles;
    }

    /**
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private function isStringMap(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }

    private function absoluteWorkingPath(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return $path;
        }

        return $this->workingDirectory . '/' . ltrim($path, '/');
    }

    private function displayWorkingPath(string $path): string
    {
        $workingDirectory = rtrim($this->workingDirectory, '/');
        if (str_starts_with($path, $workingDirectory . '/')) {
            return ltrim(substr($path, strlen($workingDirectory)), '/');
        }

        return $path;
    }

    /**
     * @param array<mixed, mixed> $run
     * @return array<string, mixed>
     */
    private function runSummary(array $run): array
    {
        $summary = [];
        foreach (['started_at', 'finished_at', 'exit_code', 'task_filter'] as $key) {
            if (array_key_exists($key, $run)) {
                $summary[$key] = $run[$key];
            }
        }

        $results = $run['results'] ?? null;
        if (is_array($results)) {
            $summary['results'] = [];
            foreach ($results as $result) {
                if (!is_array($result)) {
                    continue;
                }

                $resultSummary = [];
                foreach (['task', 'successful', 'skipped', 'message', 'finished_at'] as $key) {
                    if (array_key_exists($key, $result)) {
                        $resultSummary[$key] = $result[$key];
                    }
                }

                $resultContext = $result['context'] ?? null;
                if (is_array($resultContext)) {
                    $contextSummary = [];
                    foreach (['provider', 'configured_provider', 'routing_reason', 'exit_code', 'timed_out'] as $key) {
                        if (array_key_exists($key, $resultContext)) {
                            $contextSummary[$key] = $resultContext[$key];
                        }
                    }
                    if ($contextSummary !== []) {
                        $resultSummary['context'] = $contextSummary;
                    }
                }

                $summary['results'][] = $resultSummary;
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function processResultToArray(ProcessResult $processResult): array
    {
        $result = [
            'command' => $processResult->command,
            'working_directory' => $processResult->workingDirectory,
            'exit_code' => $processResult->exitCode,
            'stdout' => $processResult->stdout,
            'stderr' => $processResult->stderr,
            'successful' => $processResult->successful(),
        ];

        if ($processResult->timedOut) {
            $result['timed_out'] = true;
        }
        if ($processResult->exceptionMessage !== null) {
            $result['exception'] = $processResult->exceptionMessage;
        }

        return $result;
    }

    /**
     * @param list<string> $changedFiles
     */
    private function markReviewed(RunContext $context, mixed $latestRunStartedAt, int $reviewedRunCount, array $changedFiles = []): void
    {
        if ($context->dryRun) {
            return;
        }

        $context->setMetadataValue('self_improvement.last_reviewed_at', time());
        $context->setMetadataValue('self_improvement.last_reviewed_run_count', $reviewedRunCount);
        $context->setMetadataValue('self_improvement.last_changed_files', $changedFiles);
        if (is_int($latestRunStartedAt)) {
            $context->setMetadataValue('self_improvement.last_reviewed_run_started_at', $latestRunStartedAt);
        }
    }
}
