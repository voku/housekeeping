<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Contract;

interface StateStore
{
    /**
     * @return array<string, mixed>
     */
    public function load(): array;

    /**
     * @param array<string, mixed> $state
     */
    public function save(array $state): void;
}
