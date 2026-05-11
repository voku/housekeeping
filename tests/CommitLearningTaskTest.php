<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\CommitLearningTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class CommitLearningTaskTest extends TestCase
{
    use StateAssertions;

    public function testCommitLearningForwardsRecentGitHistoryAndStoresMetadata(): void
    {
        $repositoryRoot = $this->createGitRepository();
        $provider = new class implements ProviderAdapter {
            /** @var array<string, mixed>|null */
            public ?array $payload = null;

            public function name(): string
            {
                return 'local-null-provider';
            }

            public function isAvailable(RunContext $context): bool
            {
                return true;
            }

            public function execute(ProviderRequest $request): ProviderResult
            {
                $this->payload = $request->payload;

                return ProviderResult::success('Accepted.', [
                    'provider_output' => [
                        'summary' => 'Structured learning summary.',
                    ],
                    'stdout' => 'Follow the existing commit patterns.',
                ]);
            }
        };
        $store = new InMemoryStateStore([
            'tasks' => [],
            'providers' => [],
            'runs' => [],
            'metadata' => [
                'project' => [
                    'todo_files' => ['TODO.md'],
                ],
            ],
        ]);
        $context = new RunContext(
            false,
            null,
            time(),
            [
                'paths' => [
                    'repository_root' => $repositoryRoot,
                ],
                'providers' => [
                    'local-null-provider' => [
                        'enabled' => true,
                        'daily_budget' => 10,
                        'cooldown_seconds' => 0,
                    ],
                ],
            ],
            $store->load(),
            [],
            $store,
            new JsonLogger($repositoryRoot . '/var/logs/housekeeping.log'),
            ['local-null-provider' => $provider],
        );

        try {
            $result = (new CommitLearningTask(3600, 'local-null-provider', new ProcessExecutor(), $repositoryRoot, 10))->run($context);

            self::assertTrue($result->successful);
            self::assertIsArray($provider->payload);
            self::assertSame($repositoryRoot, $provider->payload['repository_root'] ?? null);
            $commits = $provider->payload['commits'] ?? null;
            self::assertIsArray($commits);
            self::assertCount(2, $commits);
            self::assertIsArray($commits[0] ?? null);
            self::assertSame('Add worker', $commits[0]['subject'] ?? null);
            self::assertSame(['src/Worker.php'], $commits[0]['files'] ?? null);
            self::assertSame('Structured learning summary.', $this->stateAt($context->state(), 'metadata.learning.last_provider_output'));
            self::assertSame('Structured learning summary.', $this->stateAt($context->state(), 'metadata.learning.last_summary'));
            self::assertSame(2, $this->stateAt($context->state(), 'metadata.learning.last_commit_count'));
            self::assertIsString($this->stateAt($context->state(), 'metadata.learning.last_learned_head'));
        } finally {
            (new Filesystem())->remove($repositoryRoot);
        }
    }

    public function testCommitLearningSkipsWhenHeadWasAlreadyLearned(): void
    {
        $repositoryRoot = $this->createGitRepository();
        $head = trim($this->runGit(['rev-parse', 'HEAD'], $repositoryRoot));
        $store = new InMemoryStateStore([
            'tasks' => [],
            'providers' => [],
            'runs' => [],
            'metadata' => [
                'learning' => [
                    'last_learned_head' => $head,
                ],
            ],
        ]);

        try {
            $result = (new CommitLearningTask(3600, 'local-null-provider', new ProcessExecutor(), $repositoryRoot, 10))->run(new RunContext(
                false,
                null,
                time(),
                [
                    'paths' => [
                        'repository_root' => $repositoryRoot,
                    ],
                    'providers' => [
                        'local-null-provider' => [
                            'enabled' => true,
                            'daily_budget' => 10,
                            'cooldown_seconds' => 0,
                        ],
                    ],
                ],
                $store->load(),
                [],
                $store,
                new JsonLogger($repositoryRoot . '/var/logs/housekeeping.log'),
                [],
            ));

            self::assertTrue($result->successful);
            self::assertTrue($result->skipped);
            self::assertSame('No new commits were found to learn from.', $result->message);
        } finally {
            (new Filesystem())->remove($repositoryRoot);
        }
    }

    private function createGitRepository(): string
    {
        $repositoryRoot = sys_get_temp_dir() . '/agent-cron-git-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir([$repositoryRoot . '/src']);
        $this->runGit(['init'], $repositoryRoot);
        $this->runGit(['config', 'user.email', 'tests@example.com'], $repositoryRoot);
        $this->runGit(['config', 'user.name', 'Tests'], $repositoryRoot);

        file_put_contents($repositoryRoot . '/README.md', '# Project');
        file_put_contents($repositoryRoot . '/TODO.md', '- keep docs aligned');
        $this->runGit(['add', 'README.md', 'TODO.md'], $repositoryRoot);
        $this->runGit(['commit', '-m', 'Initial docs'], $repositoryRoot);

        file_put_contents($repositoryRoot . '/src/Worker.php', "<?php\n");
        $this->runGit(['add', 'src/Worker.php'], $repositoryRoot);
        $this->runGit(['commit', '-m', 'Add worker'], $repositoryRoot);

        return $repositoryRoot;
    }

    /**
     * @param list<string> $arguments
     */
    private function runGit(array $arguments, string $workingDirectory): string
    {
        $process = new Process(['git', ...$arguments], $workingDirectory);
        $process->mustRun();

        return $process->getOutput();
    }
}
