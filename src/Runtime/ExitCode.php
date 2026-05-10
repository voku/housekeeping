<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

final class ExitCode
{
    public const SUCCESS = 0;
    public const TASK_FAILED = 1;
    public const INVALID_CONFIG = 2;
    public const LOCK_HELD = 3;
    public const PROVIDER_UNAVAILABLE = 4;
    public const RUNTIME_BUDGET_EXCEEDED = 5;
}
