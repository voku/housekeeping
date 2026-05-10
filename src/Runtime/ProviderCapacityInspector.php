<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

final readonly class ProviderCapacityInspector
{
    public function __construct(private ProcessExecutor $processExecutor = new ProcessExecutor())
    {
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     * @return list<ProviderCapacityReport>
     */
    public function inspect(array $config, array $state): array
    {
        $providers = $config['providers'] ?? null;
        if (!is_array($providers)) {
            return [];
        }

        $reports = [];
        foreach ($providers as $providerName => $providerConfig) {
            if (!is_string($providerName) || $providerName === 'local-null-provider' || !is_array($providerConfig)) {
                continue;
            }
            /** @var array<string, mixed> $typedProviderConfig */
            $typedProviderConfig = $providerConfig;

            $reports[] = $this->inspectProvider($providerName, $typedProviderConfig, $state);
        }

        usort($reports, $this->compare(...));

        return $reports;
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @param array<string, mixed> $state
     */
    private function inspectProvider(string $providerName, array $providerConfig, array $state): ProviderCapacityReport
    {
        $enabled = ($providerConfig['enabled'] ?? false) === true;
        $budget = $this->positiveInt($providerConfig['daily_budget'] ?? 0);
        $used = $this->providerUsage($state, $providerName);
        $budgetRemaining = $budget > 0 ? max($budget - $used, 0) : null;
        $cooldownRemaining = $this->cooldownRemainingSeconds($providerConfig, $state, $providerName);
        $probe = $this->probeProvider($providerName, $providerConfig);

        $status = 'ready';
        if (!$enabled) {
            $status = 'disabled';
        } elseif ($budgetRemaining === 0) {
            $status = 'budget-exhausted';
        } elseif ($cooldownRemaining > 0) {
            $status = 'cooldown-active';
        } elseif ($probe['status'] === 'failed') {
            $status = 'probe-failed';
        } elseif (($probe['external_remaining_ratio'] ?? null) !== null && $probe['external_remaining_ratio'] <= 0.0) {
            $status = 'external-exhausted';
        } elseif ($probe['status'] === 'not-configured') {
            $status = 'ready-no-probe';
        }

        return new ProviderCapacityReport(
            $providerName,
            $enabled,
            $status,
            $budget > 0 ? $budget : null,
            $used,
            $budgetRemaining,
            $cooldownRemaining,
            $probe['external_remaining_ratio'] ?? null,
            $probe['external_reset_at'] ?? null,
            $probe['probe_command'] ?? null,
            $probe['probe_message'] ?? null,
            $probe['external_metrics'] ?? [],
        );
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @return array{
     *     status: 'ok'|'failed'|'not-configured',
     *     external_remaining_ratio?: float|null,
     *     external_reset_at?: int|null,
     *     probe_command?: list<string>|null,
     *     probe_message?: string|null,
     *     external_metrics?: list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     * }
     */
    private function probeProvider(string $providerName, array $providerConfig): array
    {
        $command = $this->stringList($providerConfig['resource_command'] ?? []);
        if ($command === []) {
            return [
                'status' => 'not-configured',
                'probe_message' => 'No resource command configured.',
            ];
        }

        $workingDirectory = is_string($providerConfig['working_directory'] ?? null) ? $providerConfig['working_directory'] : dirname(__DIR__, 2);
        $timeoutSeconds = $this->positiveInt($providerConfig['timeout_seconds'] ?? 60);
        $process = $this->processExecutor->execute($command, $workingDirectory, $timeoutSeconds);

        if (!$process->successful()) {
            return [
                'status' => 'failed',
                'probe_command' => $command,
                'probe_message' => $this->failureMessage($process),
            ];
        }

        $metrics = $this->metricsFromOutput($providerName, $process->stdout, $process->stderr);
        if ($metrics === []) {
            return [
                'status' => 'failed',
                'probe_command' => $command,
                'probe_message' => 'Probe command succeeded but returned no parseable capacity data.',
            ];
        }

        $remainingRatios = array_map(static fn (array $metric): float => $metric['remaining_ratio'], $metrics);
        $resetAt = null;
        foreach ($metrics as $metric) {
            $metricResetAt = $metric['reset_at'];
            if ($metricResetAt === null) {
                continue;
            }
            if ($resetAt === null || $metricResetAt < $resetAt) {
                $resetAt = $metricResetAt;
            }
        }

        return [
            'status' => 'ok',
            'external_remaining_ratio' => min($remainingRatios),
            'external_reset_at' => $resetAt,
            'probe_command' => $command,
            'probe_message' => sprintf('Parsed %d external limit(s).', count($metrics)),
            'external_metrics' => $metrics,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function providerUsage(array $state, string $providerName): int
    {
        $usage = $this->stateValue($state, 'providers.' . $providerName . '.usage.' . gmdate('Y-m-d'));

        return is_int($usage) ? $usage : 0;
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @param array<string, mixed> $state
     */
    private function cooldownRemainingSeconds(array $providerConfig, array $state, string $providerName): int
    {
        $cooldown = $this->positiveInt($providerConfig['cooldown_seconds'] ?? 0);
        $lastUsedAt = $this->stateValue($state, 'providers.' . $providerName . '.last_used_at');
        if ($cooldown < 1 || !is_int($lastUsedAt)) {
            return 0;
        }

        return max(($lastUsedAt + $cooldown) - time(), 0);
    }

    /**
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function metricsFromOutput(string $providerName, string $stdout, string $stderr): array
    {
        $output = trim($stdout);
        if ($output !== '') {
            $decoded = json_decode($output, true);
            if (is_array($decoded)) {
                $metrics = $this->metricsFromJson($decoded);
                if ($metrics !== []) {
                    return $metrics;
                }
            }
        }

        $combinedOutput = trim($stdout . PHP_EOL . $stderr);
        if ($combinedOutput === '') {
            return [];
        }

        return $this->metricsFromText($providerName, $combinedOutput);
    }

    /**
     * @param array<mixed> $payload
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function metricsFromJson(array $payload, string $path = ''): array
    {
        $metrics = [];
        $metric = $this->metricFromArray($payload, $path);
        if ($metric !== null) {
            $metrics[] = $metric;
        }

        foreach ($payload as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }
            foreach ($this->metricsFromJson($value, $path === '' ? $key : $path . '.' . $key) as $childMetric) {
                $metrics[] = $childMetric;
            }
        }

        return $this->uniqueMetrics($metrics);
    }

    /**
     * @param array<mixed> $payload
     * @return array{label: string, remaining_ratio: float, reset_at: int|null}|null
     */
    private function metricFromArray(array $payload, string $path): ?array
    {
        $remainingRatio = $this->extractRemainingRatio($payload);
        if ($remainingRatio === null) {
            return null;
        }

        $label = $this->metricLabel($payload, $path);
        $resetAt = $this->extractResetAt($payload);

        return [
            'label' => $label,
            'remaining_ratio' => $this->clampRatio($remainingRatio),
            'reset_at' => $resetAt,
        ];
    }

    /**
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function metricsFromText(string $providerName, string $output): array
    {
        preg_match_all('/^(?P<label>.+?)\s+(?P<percent>\d+(?:\.\d+)?)%\s*(?P<mode>left|remaining|used)?(?:[^\\n]*?resets?\s+(?P<reset>[^\\n]+))?$/mi', $output, $matches, PREG_SET_ORDER);

        $metrics = [];
        foreach ($matches as $match) {
            $label = trim($match['label'], " \t\n\r\0\x0B:-");
            $percent = (float) $match['percent'];
            $mode = strtolower(trim((string) ($match['mode'] ?? '')));
            $remainingRatio = $mode === 'used' ? (100.0 - $percent) / 100.0 : $percent / 100.0;
            $metrics[] = [
                'label' => $label !== '' ? $label : $providerName,
                'remaining_ratio' => $this->clampRatio($remainingRatio),
                'reset_at' => $this->parseResetValue($match['reset'] ?? null),
            ];
        }

        return $this->uniqueMetrics($metrics);
    }

    /**
     * @param list<array{label: string, remaining_ratio: float, reset_at: int|null}> $metrics
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function uniqueMetrics(array $metrics): array
    {
        $unique = [];
        foreach ($metrics as $metric) {
            $key = $metric['label'] . '|' . $metric['remaining_ratio'] . '|' . ($metric['reset_at'] ?? 'null');
            $unique[$key] = $metric;
        }

        return array_values($unique);
    }

    /**
     * @param array<mixed> $payload
     */
    private function extractRemainingRatio(array $payload): ?float
    {
        foreach (['remaining_ratio', 'ratio_remaining', 'remainingFraction', 'remaining_fraction'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
        }

        foreach (['remaining_percent', 'percent_remaining', 'remainingPercent', 'left_percent', 'percent_left'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return ((float) $value) / 100.0;
            }
        }

        foreach (['used_percent', 'percent_used', 'usedPercent'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return (100.0 - (float) $value) / 100.0;
            }
        }

        $used = $this->numericField($payload, ['used', 'usage', 'consumed', 'current']);
        $total = $this->numericField($payload, ['total', 'limit', 'quota', 'maximum', 'max']);
        if ($used !== null && $total !== null && $total > 0.0) {
            return ($total - $used) / $total;
        }

        return null;
    }

    /**
     * @param array<mixed> $payload
     */
    private function extractResetAt(array $payload): ?int
    {
        foreach (['reset_at', 'resets_at', 'next_reset_at', 'resetAt', 'nextResetAt'] as $key) {
            $resetAt = $this->parseResetValue($payload[$key] ?? null);
            if ($resetAt !== null) {
                return $resetAt;
            }
        }

        foreach (['reset_in_seconds', 'resets_in_seconds', 'seconds_until_reset', 'resetInSeconds'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return time() + (int) $value;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $payload
     */
    private function metricLabel(array $payload, string $path): string
    {
        foreach (['label', 'name', 'metric', 'window', 'period', 'plan'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if ($path !== '') {
            $segments = explode('.', $path);

            return (string) end($segments);
        }

        return 'limit';
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $keys
     */
    private function numericField(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function parseResetValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 1000000000000 ? (int) floor($value / 1000) : $value;
        }
        if (is_float($value)) {
            $intValue = (int) round($value);

            return $intValue > 1000000000000 ? (int) floor($intValue / 1000) : $intValue;
        }
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (ctype_digit($trimmed)) {
            $intValue = (int) $trimmed;

            return $intValue > 1000000000000 ? (int) floor($intValue / 1000) : $intValue;
        }

        $relative = $this->relativeSeconds($trimmed);
        if ($relative !== null) {
            return time() + $relative;
        }

        $timestamp = strtotime($trimmed);

        return $timestamp === false ? null : $timestamp;
    }

    private function relativeSeconds(string $value): ?int
    {
        if (!preg_match_all('/(?P<amount>\d+)\s*(?P<unit>d|h|m|s)/i', $value, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $seconds = 0;
        foreach ($matches as $match) {
            $amount = (int) $match['amount'];
            $unit = strtolower($match['unit']);
            $seconds += match ($unit) {
                'd' => $amount * 86400,
                'h' => $amount * 3600,
                'm' => $amount * 60,
                's' => $amount,
                default => 0,
            };
        }

        return $seconds > 0 ? $seconds : null;
    }

    private function compare(ProviderCapacityReport $left, ProviderCapacityReport $right): int
    {
        $leftPriority = $this->statusPriority($left->status);
        $rightPriority = $this->statusPriority($right->status);
        if ($leftPriority !== $rightPriority) {
            return $rightPriority <=> $leftPriority;
        }

        $leftHasRatio = $left->externalRemainingRatio !== null ? 1 : 0;
        $rightHasRatio = $right->externalRemainingRatio !== null ? 1 : 0;
        if ($leftHasRatio !== $rightHasRatio) {
            return $rightHasRatio <=> $leftHasRatio;
        }
        if ($left->externalRemainingRatio !== null && $right->externalRemainingRatio !== null && $left->externalRemainingRatio !== $right->externalRemainingRatio) {
            return $right->externalRemainingRatio <=> $left->externalRemainingRatio;
        }

        $leftResetAt = $left->externalResetAt ?? PHP_INT_MAX;
        $rightResetAt = $right->externalResetAt ?? PHP_INT_MAX;
        if ($leftResetAt !== $rightResetAt) {
            return $leftResetAt <=> $rightResetAt;
        }

        $leftBudgetRemaining = $left->internalBudgetRemaining ?? PHP_INT_MAX;
        $rightBudgetRemaining = $right->internalBudgetRemaining ?? PHP_INT_MAX;
        if ($leftBudgetRemaining !== $rightBudgetRemaining) {
            return $rightBudgetRemaining <=> $leftBudgetRemaining;
        }

        if ($left->cooldownRemainingSeconds !== $right->cooldownRemainingSeconds) {
            return $left->cooldownRemainingSeconds <=> $right->cooldownRemainingSeconds;
        }

        return strcmp($left->provider, $right->provider);
    }

    private function statusPriority(string $status): int
    {
        return match ($status) {
            'ready' => 7,
            'ready-no-probe' => 6,
            'probe-failed' => 5,
            'external-exhausted' => 4,
            'cooldown-active' => 3,
            'budget-exhausted' => 2,
            'disabled' => 1,
            default => 0,
        };
    }

    private function failureMessage(ProcessResult $process): string
    {
        if ($process->timedOut) {
            return 'Probe command timed out.';
        }
        if ($process->exceptionMessage !== null) {
            return $process->exceptionMessage;
        }
        $output = $process->combinedOutput();
        if ($output !== '') {
            return $output;
        }

        return 'Probe command failed with exit code ' . $process->exitCode . '.';
    }

    /**
     * @param array<string, mixed> $state
     */
    private function stateValue(array $state, string $path): mixed
    {
        $value = $state;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function positiveInt(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }

    private function clampRatio(float $ratio): float
    {
        return max(0.0, min(1.0, $ratio));
    }
}
