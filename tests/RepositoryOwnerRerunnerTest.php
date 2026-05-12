<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\RepositoryOwnerRerunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class RepositoryOwnerRerunnerTest extends TestCase
{
    public function testRootRunIsReexecutedAsRepositoryOwner(): void
    {
        $capturedUserName = null;
        $capturedCommand = null;
        $capturedWorkingDirectory = null;

        $rerunner = new class extends RepositoryOwnerRerunner {
            public ?string $capturedUserName = null;

            /** @var list<string>|null */
            public ?array $capturedCommand = null;

            public ?string $capturedWorkingDirectory = null;
            public ?int $capturedTimeoutSeconds = null;

            protected function currentUserId(): int
            {
                return 0;
            }

            protected function ownerUserId(string $path): int
            {
                return 1000;
            }

            protected function userNameForId(int $userId): string
            {
                return 'moellekenl';
            }

            protected function currentWorkingDirectory(): string
            {
                return '/workdir';
            }

            protected function rerunAsUser(
                string $userName,
                array $command,
                ?string $workingDirectory,
                int $timeoutSeconds,
                \Symfony\Component\Console\Output\OutputInterface $output,
            ): int {
                $this->capturedUserName = $userName;
                $this->capturedCommand = $command;
                $this->capturedWorkingDirectory = $workingDirectory;
                $this->capturedTimeoutSeconds = $timeoutSeconds;

                return 23;
            }
        };

        $output = new BufferedOutput();

        $exitCode = $rerunner->maybeRerun(
            '/app/bin/agent-cron',
            '/app/config/project.php',
            [
                'paths' => [
                    'repository_root' => '/repo',
                ],
            ],
            true,
            'todo:refine',
            $output,
        );

        self::assertSame(23, $exitCode);
        self::assertSame('moellekenl', $rerunner->capturedUserName);
        self::assertSame('/workdir', $rerunner->capturedWorkingDirectory);
        self::assertSame(
            [
                PHP_BINARY,
                '/app/bin/agent-cron',
                '--config=/app/config/project.php',
                'housekeeping:run',
                '--dry-run',
                '--task=todo:refine',
            ],
            $rerunner->capturedCommand,
        );
        self::assertSame(1020, $rerunner->capturedTimeoutSeconds);
        self::assertStringContainsString('Re-running housekeeping as repository owner "moellekenl".', $output->fetch());
    }

    public function testNonRootRunDoesNotReexecute(): void
    {
        $rerunner = new class extends RepositoryOwnerRerunner {
            protected function currentUserId(): int
            {
                return 1000;
            }
        };

        $output = new BufferedOutput();

        $exitCode = $rerunner->maybeRerun(
            '/app/bin/agent-cron',
            '/app/config/project.php',
            [
                'paths' => [
                    'repository_root' => '/repo',
                ],
            ],
            false,
            null,
            $output,
        );

        self::assertNull($exitCode);
        self::assertSame('', $output->fetch());
    }
}
