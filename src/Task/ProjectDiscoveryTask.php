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
        $metadata = $this->repositoryInspector->discover(
            $context->repositoryRoot(),
            $this->ignoredPaths($context),
        );
        $metadata['repository_root'] = $context->repositoryRoot();

        $context->setMetadataValue('project', $metadata);

        return TaskResult::success('Project metadata refreshed.', [
            'documentation_files' => count($metadata['documentation_files']),
            'todo_files' => count($metadata['todo_files']),
            'key_files' => count($metadata['key_files']),
        ]);
    }

    /**
     * @return list<string>
     */
    private function ignoredPaths(RunContext $context): array
    {
        $tasks = $context->config['tasks'] ?? null;
        if (!is_array($tasks)) {
            return [];
        }

        $projectDiscoveryTask = $tasks[$this->name()] ?? null;
        if (!is_array($projectDiscoveryTask)) {
            return [];
        }

        $ignoredPaths = $projectDiscoveryTask['ignored_paths'] ?? null;
        if (!is_array($ignoredPaths)) {
            return [];
        }

        $paths = [];
        foreach ($ignoredPaths as $ignoredPath) {
            if (is_string($ignoredPath) && $ignoredPath !== '') {
                $paths[] = $ignoredPath;
            }
        }

        return $paths;
    }
}
