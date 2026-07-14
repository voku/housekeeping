<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Contract\StateStore;

final class InMemoryStateStore implements StateStore
{
    public int $saveCount = 0;

    /** @param array<string, mixed> $state */
    public function __construct(public array $state = ['tasks' => [], 'providers' => [], 'runs' => []])
    {
    }

    public function load(): array
    {
        return $this->state;
    }

    public function save(array $state): void
    {
        $this->state = $state;
        ++$this->saveCount;
    }
}
