<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProcessResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class TodoRefinementTask extends AbstractProviderTask
{
    /** @var list<string> */
    private array $inputFiles;

    /** @var list<list<string>> */
    private array $contextCommands;

    /** @var list<string> */
    private array $validationCommand;

    /**
     * @param list<string> $inputFiles
     * @param list<list<string>> $contextCommands
     * @param list<string> $validationCommand
     * @param list<string> $preferredProviderNames
     */
    public function __construct(
        int $intervalSeconds,
        string $providerName,
        array $inputFiles,
        private ProcessExecutor $processExecutor = new ProcessExecutor(),
        private string $workingDirectory = __DIR__,
        array $contextCommands = [],
        array $validationCommand = [],
        private int $timeoutSeconds = 120,
        array $preferredProviderNames = [],
    ) {
        parent::__construct($intervalSeconds, $providerName, $preferredProviderNames);
        $this->inputFiles = $inputFiles;
        $this->contextCommands = $contextCommands;
        $this->validationCommand = $validationCommand;
    }

    public function name(): string
    {
        return 'todo:refine';
    }

    public function run(RunContext $context): TaskResult
    {
        $todos = $this->configuredRepositoryFiles($context, $this->inputFiles);
        if ($todos === []) {
            return TaskResult::skipped('No TODO inputs were found to refine.');
        }
        $todoPaths = array_keys($this->configuredRepositoryPaths($context, $this->inputFiles));

        $result = $this->executeProvider(
            $context,
            'Refine the repository TODO documents in place. The payload lists the tracked TODO file paths instead of embedding their full contents, so inspect and edit those files directly in the working tree. Make at most one small verifier-clean board edit per run. Prefer tightening an existing Agent Task Brief, Blocked Cards prompt, Backlog Pickup note, or helper-reference text before moving cards across lanes. Keep lane/status alignment exact: never place a Backlog ticket in READY, never change `_Count:` markers or WIP/Board Snapshot numbers unless the matching lane rows changed in the same edit, and prefer leaving the board unchanged over making a risky broad rewrite. Follow the existing TODO workflow: start from the context, keep lane counts and board snapshot coherent, prefer refining or tightening the current Kanban handoff instead of inventing new work, and validate board edits with the configured verifier workflow. Avoid copying Jira details and do not make unsafe or speculative code changes. Edit files directly instead of only describing suggestions.',
            [
                'working_directory' => $this->workingDirectory,
                'todo_file_paths' => $todoPaths,
                'workflow_context' => $this->workflowContext(),
                ...$this->sharedMetadata($context),
            ],
            'TODO refinement completed.',
        );

        if (!$result->successful || $result->skipped || $context->dryRun) {
            return $result;
        }

        $changedFiles = $this->changedFiles($context, $todos);
        if ($changedFiles === []) {
            return TaskResult::skipped('TODO refinement produced no TODO document changes.');
        }

        if ($this->validationCommand !== []) {
            $validationResult = $this->processExecutor->execute($this->validationCommand, $this->workingDirectory, $this->timeoutSeconds);
            if (!$validationResult->successful()) {
                $this->restoreFiles($context, $todos);

                return $this->validationFailure($validationResult);
            }
        }

        return $result->withContext(['changed_files' => $changedFiles]);
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

    /**
     * @return list<array{command: list<string>, output: string}>
     */
    private function workflowContext(): array
    {
        $context = [];

        foreach ($this->contextCommands as $command) {
            $result = $this->processExecutor->execute($command, $this->workingDirectory, $this->timeoutSeconds);
            if (!$result->successful()) {
                continue;
            }

            $output = trim($result->combinedOutput());
            if ($output === '') {
                continue;
            }

            $context[] = [
                'command' => $command,
                'output' => $output,
            ];
        }

        return $context;
    }

    private function validationFailure(ProcessResult $processResult): TaskResult
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

        return TaskResult::failure('TODO board validation command failed after refinement.', $context);
    }

    /**
     * @param array<string, string> $documents
     */
    private function restoreFiles(RunContext $context, array $documents): void
    {
        foreach ($documents as $path => $contents) {
            $absolutePath = str_starts_with($path, '/')
                ? $path
                : $context->repositoryRoot() . '/' . ltrim($path, '/');

            file_put_contents($absolutePath, $contents);
        }
    }
}
