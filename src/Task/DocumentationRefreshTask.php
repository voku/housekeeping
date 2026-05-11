<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

final readonly class DocumentationRefreshTask extends AbstractProviderTask
{
    /** @var list<string> */
    private array $inputFiles;

    /** @var list<string> */
    private array $contextFiles;

    /**
     * @param list<string> $inputFiles
     * @param list<string> $contextFiles
     */
    public function __construct(
        int $intervalSeconds,
        string $providerName,
        array $inputFiles,
        array $contextFiles = [],
    ) {
        parent::__construct($intervalSeconds, $providerName);
        $this->inputFiles = $inputFiles;
        $this->contextFiles = $contextFiles;
    }

    public function name(): string
    {
        return 'docs:refresh';
    }

    public function run(RunContext $context): TaskResult
    {
        $documents = $this->collectRepositoryFiles($context, $this->inputFiles, 'project.documentation_files');
        if ($documents === []) {
            return TaskResult::skipped('No documentation inputs were found for refresh.');
        }

        $codeContext = $this->collectRepositoryFiles($context, $this->contextFiles, 'project.key_files');

        return $this->executeProvider(
            $context,
            'Compare the project documentation against the current code, learned repository patterns, and recent blind-spot guidance. Return concise housekeeping guidance and safe doc-sync patch suggestions only.',
            [
                'documents' => $documents,
                'code_context' => $codeContext,
                ...$this->sharedMetadata($context),
            ],
            'Documentation refresh completed.',
        );
    }
}
