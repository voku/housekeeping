<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class DocumentationRefreshTask extends AbstractProviderTask
{
    /**
     * @param list<string> $inputFiles
     */
    public function __construct(
        int $intervalSeconds,
        string $providerName,
        private array $inputFiles,
    ) {
        parent::__construct($intervalSeconds, $providerName);
    }

    public function name(): string
    {
        return 'docs:refresh';
    }

    public function run(RunContext $context): TaskResult
    {
        $documents = $this->collectFiles();
        if ($documents === []) {
            return TaskResult::skipped('No documentation inputs were found for refresh.');
        }

        return $this->executeProvider(
            $context,
            'Review the provided documentation inputs and return a concise maintenance report with safe patch suggestions only.',
            ['documents' => $documents],
            'Documentation refresh completed.',
        );
    }

    /**
     * @return array<string, string>
     */
    private function collectFiles(): array
    {
        $documents = [];
        foreach ($this->inputFiles as $path) {
            if (!is_file($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }
            $documents[$path] = $contents;
        }

        return $documents;
    }
}
