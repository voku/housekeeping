<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProcessResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class SelectedFilesMaintenanceTask extends AbstractProviderTask
{
    /**
     * @param list<string> $selectionCommand
     * @param list<string> $contextFiles
     * @param list<string> $preferredProviderNames
     */
    public function __construct(
        private string $taskName,
        int $intervalSeconds,
        string $providerName,
        private ProcessExecutor $processExecutor,
        private string $workingDirectory,
        private array $selectionCommand,
        private string $prompt,
        private string $successMessage,
        private int $timeoutSeconds = 120,
        private int $maxFiles = 12,
        private array $contextFiles = [],
        array $preferredProviderNames = [],
    ) {
        parent::__construct($intervalSeconds, $providerName, $preferredProviderNames);
    }

    public function name(): string
    {
        return $this->taskName;
    }

    public function run(RunContext $context): TaskResult
    {
        $selectionResult = $this->processExecutor->execute($this->selectionCommand, $this->workingDirectory, $this->timeoutSeconds);
        if (!$selectionResult->successful()) {
            if ($this->selectionReturnedNoMatches($selectionResult)) {
                return TaskResult::skipped('No candidate files were selected.');
            }

            return $this->selectionFailure($selectionResult);
        }

        $candidatePaths = $this->candidatePaths($selectionResult->stdout);
        if ($candidatePaths === []) {
            return TaskResult::skipped('No candidate files were selected.');
        }

        $selectedFiles = $this->readFiles($context, $candidatePaths);
        if ($selectedFiles === []) {
            return TaskResult::skipped('No selected candidate files could be read.');
        }

        $contextFiles = $this->readFiles($context, $this->contextFiles);

        $result = $this->executeProvider(
            $context,
            $this->prompt,
            [
                'working_directory' => $this->workingDirectory,
                'selection_command' => $this->selectionCommand,
                'selected_files' => $selectedFiles,
                'context_files' => $contextFiles,
                ...$this->sharedMetadata($context),
            ],
            $this->successMessage,
        );

        if (!$result->successful || $result->skipped || $context->dryRun) {
            return $result;
        }

        $changedFiles = $this->changedFiles($context, $selectedFiles);
        if ($changedFiles === []) {
            return TaskResult::skipped(sprintf('%s produced no file changes.', $this->taskName));
        }

        return $result->withContext(['changed_files' => $changedFiles]);
    }

    /**
     * @return list<string>
     */
    private function candidatePaths(string $stdout): array
    {
        $paths = [];
        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $paths[] = $line;
            }
        }

        return array_slice(array_values(array_unique($paths)), 0, $this->maxFiles);
    }

    private function selectionReturnedNoMatches(ProcessResult $processResult): bool
    {
        return !$processResult->timedOut
            && $processResult->exceptionMessage === null
            && $processResult->exitCode === 1
            && trim($processResult->stdout) === ''
            && trim($processResult->stderr) === '';
    }

    /**
     * @param array<string, string> $beforeDocuments
     * @return list<string>
     */
    private function changedFiles(RunContext $context, array $beforeDocuments): array
    {
        $afterDocuments = $this->readFiles($context, array_keys($beforeDocuments));
        $changedFiles = [];

        foreach ($beforeDocuments as $path => $contents) {
            if (($afterDocuments[$path] ?? null) !== $contents) {
                $changedFiles[] = $path;
            }
        }

        return $changedFiles;
    }

    private function selectionFailure(ProcessResult $processResult): TaskResult
    {
        $context = [
            'command' => $processResult->command,
            'working_directory' => $processResult->workingDirectory,
            'exit_code' => $processResult->exitCode,
            'stdout' => $processResult->stdout,
            'stderr' => $processResult->stderr,
        ];

        if ($processResult->timedOut) {
            $context['timed_out'] = true;
        }
        if ($processResult->exceptionMessage !== null) {
            $context['exception'] = $processResult->exceptionMessage;
        }

        return TaskResult::failure('Candidate file selection command failed.', $context);
    }
}
