<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class TodoRefinementTask extends AbstractProviderTask
{
    /** @var list<string> */
    private array $inputFiles;

    /**
     * @param list<string> $inputFiles
     */
    public function __construct(
        int $intervalSeconds,
        string $providerName,
        array $inputFiles,
    ) {
        parent::__construct($intervalSeconds, $providerName);
        $this->inputFiles = $inputFiles;
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
            'Convert the project TODO notes into concise actionable maintenance items that respect the learned repository patterns and recent blind-spot guidance. Do not suggest unsafe automation or unreviewed changes.',
            [
                'todo_documents' => $todos,
                ...$this->sharedMetadata($context),
            ],
            'TODO refinement completed.',
        );
    }
}
