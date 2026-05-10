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
        $todos = $this->collectRepositoryFiles($context, $this->inputFiles, 'project.todo_files');
        if ($todos === []) {
            return TaskResult::skipped('No TODO inputs were found to refine.');
        }

        return $this->executeProvider(
            $context,
            'Convert the project TODO notes into concise actionable maintenance items that respect the learned repository patterns. Do not suggest unsafe automation or unreviewed changes.',
            [
                'todo_documents' => $todos,
                'project_metadata' => $context->metadataValue('project'),
                'learning_metadata' => $context->metadataValue('learning'),
            ],
            'TODO refinement completed.',
        );
    }
}
