<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Provider;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\RunContext;

final class NullProvider implements ProviderAdapter
{
    public int $calls = 0;

    public function name(): string
    {
        return 'local-null-provider';
    }

    public function isAvailable(RunContext $context): bool
    {
        return true;
    }

    public function execute(ProviderRequest $request): ProviderResult
    {
        ++$this->calls;

        return ProviderResult::success('Null provider accepted request.', [
            'task' => $request->taskName,
            'prompt_length' => strlen($request->prompt),
        ]);
    }
}
