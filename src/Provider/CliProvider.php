<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Provider;

use HousekeepingAgentCron\Contract\ProviderAdapter;
use HousekeepingAgentCron\Runtime\ProcessExecutor;
use HousekeepingAgentCron\Runtime\ProviderRequest;
use HousekeepingAgentCron\Runtime\ProviderResult;
use HousekeepingAgentCron\Runtime\RunContext;

abstract readonly class CliProvider implements ProviderAdapter
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        private ProcessExecutor $processExecutor,
        private array $command,
        private string $workingDirectory,
        private int $timeoutSeconds,
    ) {
    }

    public function isAvailable(RunContext $context): bool
    {
        return $this->command !== [];
    }

    public function execute(ProviderRequest $request): ProviderResult
    {
        if ($this->command === []) {
            return ProviderResult::failure('Provider command is not configured.', ['provider' => $this->name()]);
        }

        $prompt = $this->formatPrompt($request);
        $command = $this->commandWithPrompt($prompt);
        if ($prompt === '') {
            return ProviderResult::failure('Unable to encode provider request payload.');
        }

        $process = $this->processExecutor->execute($command, $this->workingDirectory, $this->timeoutSeconds, $prompt);
        if ($process->timedOut) {
            return ProviderResult::failure('Provider command timed out.', ['provider' => $this->name(), 'command' => $process->command]);
        }
        if (!$process->successful()) {
            return ProviderResult::failure('Provider command failed.', [
                'provider' => $this->name(),
                'command' => $process->command,
                'exit_code' => $process->exitCode,
                'stdout' => $process->stdout,
                'stderr' => $process->stderr,
            ]);
        }

        return ProviderResult::success('Provider command completed.', [
            'provider' => $this->name(),
            'command' => $process->command,
            'prompt' => $prompt,
            'stdout' => trim($process->stdout),
            'stderr' => trim($process->stderr),
        ]);
    }

    private function formatPrompt(ProviderRequest $request): string
    {
        $payload = json_encode($request->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($payload === false) {
            return '';
        }

        return trim(implode(PHP_EOL . PHP_EOL, [
            'You are an autonomous housekeeping coding agent running from cron.',
            'Task: ' . $request->taskName,
            'Goal: ' . $request->prompt,
            'Payload:' . PHP_EOL . $payload,
        ]));
    }

    /**
     * @return list<string>
     */
    private function commandWithPrompt(string $prompt): array
    {
        $command = $this->command;
        if (!in_array('--yolo', $command, true)) {
            $command[] = '--yolo';
        }

        foreach ($command as $index => $argument) {
            if ($argument === '%PROMPT%') {
                $command[$index] = $prompt;

                return $command;
            }
        }

        $command[] = $prompt;

        return $command;
    }
}
