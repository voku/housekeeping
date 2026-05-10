<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Contract;

use HousekeepingAgentCron\Runtime\RunContext;
use HousekeepingAgentCron\Runtime\TaskResult;

interface HousekeepingTask
{
    public function name(): string;

    public function isDue(RunContext $context): bool;

    public function run(RunContext $context): TaskResult;
}
