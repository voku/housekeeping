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
     * @param list<string> $arguments
     */
    public function __construct(
        private ProcessExecutor $processExecutor,
        private array $command,
        private array $arguments,
        private string $workingDirectory,
        private int $timeoutSeconds,
        private bool $appendYolo,
        private ?string $model = null,
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
        if ($prompt === '') {
            return ProviderResult::failure('Unable to encode provider request payload.');
        }
        $command = $this->commandForPrompt($prompt);

        $process = $this->processExecutor->execute($command, $this->workingDirectory, $this->timeoutSeconds, $this->inputForPrompt($prompt));
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

    /**
     * @return list<string>
     */
    abstract protected function commandForPrompt(string $prompt): array;

    protected function inputForPrompt(string $prompt): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    final protected function configuredCommand(): array
    {
        return $this->command;
    }

    /**
     * @return list<string>
     */
    final protected function configuredArguments(): array
    {
        return $this->arguments;
    }

    final protected function configuredModel(): ?string
    {
        return $this->model;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    final protected function appendTokenIfYoloConfigured(array $command, string $token): array
    {
        if ($this->appendYolo && !in_array($token, $command, true)) {
            $command[] = $token;
        }

        return $command;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    final protected function appendYoloArgumentPairIfConfigured(array $command, string $option, string $value): array
    {
        if ($this->appendYolo && !$this->hasToken($command, $option)) {
            $command[] = $option;
            $command[] = $value;
        }

        return $command;
    }

    /**
     * @param list<string> $command
     * @param list<string> $existingTokens
     * @return list<string>
     */
    final protected function appendArgumentPairIfConfigured(
        array $command,
        string $option,
        ?string $value,
        array $existingTokens = [],
    ): array {
        if ($value === null || $value === '') {
            return $command;
        }

        $tokens = $existingTokens !== [] ? $existingTokens : [$option];
        if ($this->hasToken($command, ...$tokens)) {
            return $command;
        }

        $command[] = $option;
        $command[] = $value;

        return $command;
    }

    /**
     * @param list<string> $command
     */
    final protected function hasToken(array $command, string ...$tokens): bool
    {
        foreach ($tokens as $token) {
            if (in_array($token, $command, true)) {
                return true;
            }
        }

        return false;
    }

    private function formatPrompt(ProviderRequest $request): string
    {
        $payload = json_encode($request->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($payload === false) {
            return '';
        }

        return implode(PHP_EOL . PHP_EOL, [
            'You are an autonomous housekeeping coding agent running from cron.',
            'Never run `git commit`, create commits, or otherwise mutate git history yourself; only return patch suggestions or uncommitted file changes for human review.',
            'Task: ' . $request->taskName,
            'Goal: ' . $request->prompt,
            'Payload:' . PHP_EOL . $payload,
        ]);
    }
}
