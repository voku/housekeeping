<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Provider\NullProvider;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use PHPUnit\Framework\TestCase;

final class NullProviderTest extends TestCase
{
    public function testExecuteReturnsTaskNameAndPromptLengthInContext(): void
    {
        $provider = new NullProvider();

        $result = $provider->execute(new ProviderRequest('docs:refresh', 'hello'));

        self::assertTrue($result->successful);
        self::assertSame('docs:refresh', $result->context['task'] ?? null);
        self::assertSame(5, $result->context['prompt_length'] ?? null);
    }
}
