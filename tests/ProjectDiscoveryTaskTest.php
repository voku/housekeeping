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
        file_put_contents($repositoryRoot . '/docs/guide.md', '# Guide');
        file_put_contents($repositoryRoot . '/TODO.txt', 'Ship it');
        file_put_contents($repositoryRoot . '/composer.json', '{}');
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
            $store,
            new JsonLogger($repositoryRoot . '/var/logs/housekeeping.log'),
            [],
        );

        try {
            $result = (new ProjectDiscoveryTask(3600))->run($context);
            $context->saveState();

            self::assertTrue($result->successful);
            self::assertSame(['README.md', 'docs/guide.md'], $this->stateAt($store->state, 'metadata.project.documentation_files'));
            self::assertSame(['TODO.txt'], $this->stateAt($store->state, 'metadata.project.todo_files'));
            self::assertSame(['README.md', 'composer.json'], array_values(array_intersect(
                ['README.md', 'composer.json'],
                $this->stateAt($store->state, 'metadata.project.key_files'),
            )));
        } finally {
            (new Filesystem())->remove($repositoryRoot);
        }
    }
}
