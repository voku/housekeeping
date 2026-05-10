<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProcessResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class CommitLearningTask extends AbstractProviderTask
{
    public function __construct(
        int $intervalSeconds,
        string $providerName,
        private ProcessExecutor $processExecutor,
        private string $workingDirectory,
        private int $maxCommits,
    ) {
        parent::__construct($intervalSeconds, $providerName);
    }

    public function name(): string
    {
        return 'commits:learn';
    }

    public function run(RunContext $context): TaskResult
    {
        $headResult = $this->processExecutor->execute(['git', 'rev-parse', 'HEAD'], $this->workingDirectory, 30);
        if (!$headResult->successful()) {
            return $this->commandFailure('Unable to resolve repository HEAD for commit learning.', $headResult);
        }

        $head = trim($headResult->stdout);
        $lastLearnedHeadValue = $context->metadataValue('learning.last_learned_head');
        $lastLearnedHead = is_string($lastLearnedHeadValue) && $lastLearnedHeadValue !== '' ? $lastLearnedHeadValue : null;
        if ($lastLearnedHead === $head) {
            return TaskResult::skipped('No new commits were found to learn from.');
        }

        $historyResult = $this->processExecutor->execute($this->gitLogCommand($lastLearnedHead), $this->workingDirectory, 60);
        if (!$historyResult->successful()) {
            return $this->commandFailure('Unable to read recent git history for commit learning.', $historyResult);
        }

        $commits = $this->parseCommits($historyResult->stdout);
        if ($commits === []) {
            return TaskResult::skipped('No recent commits were available to learn from.');
        }

        $result = $this->executeProvider(
            $context,
            'Study the recent commit history to learn repository patterns, decisions, and likely blind spots for future housekeeping runs. Produce concise operational guidance only.',
            [
                'repository_root' => $this->workingDirectory,
                'project_metadata' => $context->metadataValue('project'),
                'commits' => $commits,
            ],
            'Commit learning completed.',
        );

        if ($result->successful && !$result->skipped && !$context->dryRun) {
            $context->setMetadataValue('learning.last_learned_head', $head);
            $context->setMetadataValue('learning.last_learned_at', time());
            $context->setMetadataValue('learning.last_commit_count', count($commits));
            $context->setMetadataValue('learning.last_commit_subjects', array_map(
                static fn (array $commit): string => $commit['subject'],
                $commits,
            ));

            $providerOutput = $result->context['stdout'] ?? null;
            if (is_string($providerOutput) && $providerOutput !== '') {
                $context->setMetadataValue('learning.last_provider_output', $providerOutput);
            }
        }

        return $result;
    }

    /**
     * @param string|null $lastLearnedHead
     * @return list<string>
     */
    private function gitLogCommand(?string $lastLearnedHead): array
    {
        $command = [
            'git',
            'log',
            '--no-decorate',
            '--name-only',
            '--pretty=format:%x1e%H%x1f%ct%x1f%s%x1f%b',
            '--max-count=' . $this->maxCommits,
        ];

        if ($lastLearnedHead !== null) {
            $command[] = $lastLearnedHead . '..HEAD';
        }

        return $command;
    }

    /**
     * @return list<array{sha: string, committed_at: string, subject: string, body: string, files: list<string>}>
     */
    private function parseCommits(string $output): array
    {
        $commits = [];

        foreach (explode("\x1e", $output) as $record) {
            $record = ltrim($record);
            if ($record === '') {
                continue;
            }

            $lines = preg_split('/\R/', $record);
            if (!is_array($lines)) {
                continue;
            }

            $header = array_shift($lines);

            $parts = explode("\x1f", $header, 4);
            if (count($parts) !== 4) {
                continue;
            }

            [$sha, $timestamp, $subject, $body] = $parts;
            if ($sha === '' || !ctype_xdigit($sha)) {
                continue;
            }

            $files = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $files[] = $line;
                }
            }

            $commits[] = [
                'sha' => $sha,
                'committed_at' => is_numeric($timestamp) ? gmdate(DATE_ATOM, (int) $timestamp) : '',
                'subject' => $subject,
                'body' => trim($body),
                'files' => array_values(array_unique($files)),
            ];
        }

        return $commits;
    }

    private function commandFailure(string $message, ProcessResult $processResult): TaskResult
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

        return TaskResult::failure($message, $context);
    }
}
