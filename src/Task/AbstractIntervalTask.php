<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Task;

use HousekeepingAgentCron\Contract\HousekeepingTask;
use HousekeepingAgentCron\Runtime\RunContext;

abstract readonly class AbstractIntervalTask implements HousekeepingTask
{
    public function __construct(private int $intervalSeconds)
    {
    }

    public function isDue(RunContext $context): bool
    {
        if ($context->taskFilter === $this->name()) {
            return true;
        }

        $lastRun = $context->stateValue('tasks.' . $this->name() . '.last_finished_at');
        if (!is_int($lastRun)) {
            return true;
        }

        return time() - $lastRun >= $this->intervalSeconds;
    }
}
