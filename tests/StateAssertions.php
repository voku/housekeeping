<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use PHPUnit\Framework\Assert;

trait StateAssertions
{
    /**
     * @param array<string, mixed> $state
     */
    private function stateAt(array $state, string $path): mixed
    {
        $value = $state;
        foreach (explode('.', $path) as $segment) {
            Assert::assertIsArray($value);
            Assert::assertArrayHasKey($segment, $value);
            $value = $value[$segment];
        }

        return $value;
    }
}
