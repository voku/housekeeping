<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Contract;

use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\RunContext;

interface ProviderAdapter
{
    public function name(): string;

    public function isAvailable(RunContext $context): bool;

    public function execute(ProviderRequest $request): ProviderResult;
}
