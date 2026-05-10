<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\RepositoryInspector;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class ProjectDiscoveryTask extends AbstractIntervalTask
{
    public function __construct(
        int $intervalSeconds,
        private RepositoryInspector $repositoryInspector = new RepositoryInspector(),
    ) {
        parent::__construct($intervalSeconds);
    }

    public function name(): string
    {
        return 'project:discover';
    }

    public function run(RunContext $context): TaskResult
    {
        $metadata = $this->repositoryInspector->discover($context->repositoryRoot());
        $metadata['repository_root'] = $context->repositoryRoot();

        $context->setMetadataValue('project', $metadata);

        return TaskResult::success('Project metadata refreshed.', [
            'documentation_files' => count($metadata['documentation_files']),
            'todo_files' => count($metadata['todo_files']),
            'key_files' => count($metadata['key_files']),
        ]);
    }
}
