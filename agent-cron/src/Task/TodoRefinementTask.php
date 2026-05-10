<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class TodoRefinementTask extends AbstractProviderTask
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
        return 'todo:refine';
    }

    public function run(RunContext $context): TaskResult
    {
        $todos = $this->collectTodos();
        if ($todos === []) {
            return TaskResult::skipped('No TODO inputs were found to refine.');
        }

        return $this->executeProvider(
            $context,
            'Convert the provided TODO notes into concise actionable maintenance items. Do not suggest unsafe automation or unreviewed changes.',
            ['todo_documents' => $todos],
            'TODO refinement completed.',
        );
    }

    /**
     * @return array<string, string>
     */
    private function collectTodos(): array
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
