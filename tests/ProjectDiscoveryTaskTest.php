<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\JsonLogger;
use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Task\ProjectDiscoveryTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectDiscoveryTaskTest extends TestCase
{
    use StateAssertions;

    public function testProjectDiscoveryStoresRepositoryMetadata(): void
    {
        $repositoryRoot = sys_get_temp_dir() . '/agent-cron-discovery-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir([$repositoryRoot . '/docs', $repositoryRoot . '/src', $repositoryRoot . '/vendor']);
        file_put_contents($repositoryRoot . '/README.md', '# Docs');
        file_put_contents($repositoryRoot . '/QUICKSTART.md', '# Quick start');
        file_put_contents($repositoryRoot . '/AGENTS.md', '# Agents');
        file_put_contents($repositoryRoot . '/docs/guide.md', '# Guide');
        (new Filesystem())->mkdir($repositoryRoot . '/skills/run-archaeologist');
        file_put_contents($repositoryRoot . '/skills/run-archaeologist/SKILL.md', '# Skill');
        file_put_contents($repositoryRoot . '/TODO.txt', 'Ship it');
        file_put_contents($repositoryRoot . '/composer.json', '{}');
        file_put_contents($repositoryRoot . '/crontab.example', '* * * * * php bin/agent-cron housekeeping:run');
        file_put_contents($repositoryRoot . '/vendor/ignored.md', '# Ignore me');

        $store = new InMemoryStateStore();
        $context = new RunContext(
            false,
            null,
            time(),
            [
                'paths' => [
                    'repository_root' => $repositoryRoot,
                ],
                'providers' => [],
            ],
            $store->load(),
            [],
            $store,
            new JsonLogger($repositoryRoot . '/var/logs/housekeeping.log'),
            [],
        );

        try {
            $result = (new ProjectDiscoveryTask(3600))->run($context);
            $context->saveState();
            $keyFiles = $this->stateAt($store->state, 'metadata.project.key_files');

            self::assertTrue($result->successful);
            self::assertSame(['AGENTS.md', 'QUICKSTART.md', 'README.md', 'docs/guide.md', 'skills/run-archaeologist/SKILL.md'], $this->stateAt($store->state, 'metadata.project.documentation_files'));
            self::assertSame(['TODO.txt'], $this->stateAt($store->state, 'metadata.project.todo_files'));
            self::assertIsArray($keyFiles);
            self::assertContains('README.md', $keyFiles);
            self::assertContains('QUICKSTART.md', $keyFiles);
            self::assertContains('AGENTS.md', $keyFiles);
            self::assertContains('composer.json', $keyFiles);
            self::assertContains('crontab.example', $keyFiles);
        } finally {
            (new Filesystem())->remove($repositoryRoot);
        }
    }

    public function testProjectDiscoveryCanIgnoreConfiguredNestedWorkspacePaths(): void
    {
        $repositoryRoot = sys_get_temp_dir() . '/agent-cron-discovery-ignore-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir([$repositoryRoot . '/docs', $repositoryRoot . '/housekeeping/vendor']);
        file_put_contents($repositoryRoot . '/README.md', '# Root Docs');
        file_put_contents($repositoryRoot . '/docs/guide.md', '# Guide');
        file_put_contents($repositoryRoot . '/housekeeping/README.md', '# Nested workspace');
        file_put_contents($repositoryRoot . '/housekeeping/TODO.md', 'Do not index me');

        $store = new InMemoryStateStore();
        $context = new RunContext(
            false,
            null,
            time(),
            [
                'paths' => [
                    'repository_root' => $repositoryRoot,
                ],
                'tasks' => [
                    'project:discover' => [
                        'ignored_paths' => ['housekeeping'],
                    ],
                ],
                'providers' => [],
            ],
            $store->load(),
            [],
            $store,
            new JsonLogger($repositoryRoot . '/var/logs/housekeeping.log'),
            [],
        );

        try {
            $result = (new ProjectDiscoveryTask(3600))->run($context);
            $context->saveState();

            self::assertTrue($result->successful);
            self::assertSame(['README.md', 'docs/guide.md'], $this->stateAt($store->state, 'metadata.project.documentation_files'));
            self::assertSame([], $this->stateAt($store->state, 'metadata.project.todo_files'));
        } finally {
            (new Filesystem())->remove($repositoryRoot);
        }
    }
}
