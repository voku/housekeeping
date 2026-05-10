<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\JsonLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class JsonLoggerTest extends TestCase
{
    public function testConstructorThrowsWhenLogDirectoryCannotBeCreated(): void
    {
        $blockingFile = sys_get_temp_dir() . '/agent-cron-log-blocker-' . bin2hex(random_bytes(4));
        file_put_contents($blockingFile, 'block');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote('Unable to create log directory: ' . $blockingFile . ' for ' . $blockingFile . '/housekeeping.log', '/') . '/');

        try {
            new JsonLogger($blockingFile . '/housekeeping.log');
        } finally {
            (new Filesystem())->remove($blockingFile);
        }
    }
}
