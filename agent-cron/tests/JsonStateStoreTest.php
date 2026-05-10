<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\State\JsonStateStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class JsonStateStoreTest extends TestCase
{
    public function testConstructorThrowsWhenStateDirectoryCannotBeCreated(): void
    {
        $blockingFile = sys_get_temp_dir() . '/agent-cron-state-blocker-' . bin2hex(random_bytes(4));
        file_put_contents($blockingFile, 'block');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to create state directory: ' . $blockingFile . ' for ' . $blockingFile . '/state.json');

            new JsonStateStore($blockingFile . '/state.json');
        } finally {
            (new Filesystem())->remove($blockingFile);
        }
    }
}
