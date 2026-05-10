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
            new JsonStateStore($blockingFile . '/state.json');

            self::fail('Expected state store construction to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('Unable to create state directory: ' . $blockingFile . ' for ' . $blockingFile . '/state.json (', $exception->getMessage());
            self::assertStringContainsString('mkdir(): File exists', $exception->getMessage());
            self::assertStringEndsWith(')', $exception->getMessage());
        } finally {
            (new Filesystem())->remove($blockingFile);
        }
    }

    public function testLoadReturnsCompleteStateDocument(): void
    {
        $dir = sys_get_temp_dir() . '/agent-cron-state-load-' . bin2hex(random_bytes(4));
        $path = $dir . '/state.json';
        (new Filesystem())->mkdir($dir);
        file_put_contents($path, json_encode([
            'tasks' => ['demo' => ['last_message' => 'ok']],
            'providers' => ['local-null-provider' => ['usage' => ['2026-05-10' => 1]]],
            'runs' => [['exit_code' => 0]],
        ], JSON_PRETTY_PRINT));

        try {
            $state = (new JsonStateStore($path))->load();

            self::assertSame(['tasks', 'providers', 'runs'], array_keys($state));
            self::assertIsArray($state['tasks'] ?? null);
            self::assertIsArray($state['tasks']['demo'] ?? null);
            self::assertSame('ok', $state['tasks']['demo']['last_message'] ?? null);
            self::assertIsArray($state['providers'] ?? null);
            self::assertIsArray($state['providers']['local-null-provider'] ?? null);
            self::assertIsArray($state['providers']['local-null-provider']['usage'] ?? null);
            self::assertSame(1, $state['providers']['local-null-provider']['usage']['2026-05-10'] ?? null);
            self::assertIsArray($state['runs'] ?? null);
            self::assertIsArray($state['runs'][0] ?? null);
            self::assertSame(0, $state['runs'][0]['exit_code'] ?? null);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
