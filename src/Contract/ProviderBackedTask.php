<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Contract;

interface ProviderBackedTask extends HousekeepingTask
{
    public function providerName(): string;
}
