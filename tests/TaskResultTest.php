<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\TaskResult;
use PHPUnit\Framework\TestCase;

final class TaskResultTest extends TestCase
{
    public function testWithContextMergesExistingAndNewContext(): void
    {
        $result = TaskResult::success('ok', [
            'existing' => 'value',
            'keep' => 'old',
        ])->withContext([
            'keep' => 'new',
            'added' => 'field',
        ]);

        self::assertSame('value', $result->context['existing'] ?? null);
        self::assertSame('new', $result->context['keep'] ?? null);
        self::assertSame('field', $result->context['added'] ?? null);
    }
}
