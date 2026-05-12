<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class ProviderCapacityInspector
{
    private const int DEFAULT_TIMEOUT_SECONDS = 60;

    public function __construct(private ProcessExecutor $processExecutor = new ProcessExecutor())
    {
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     * @return list<ProviderCapacityReport>
     */
    public function inspect(array $config, array $state, bool $runExternalProbes = true, ?int $currentRunStartedAt = null): array
    {
        $providers = $config['providers'] ?? null;
        if (!is_array($providers)) {
            return [];
        }
        $now = time();
        $today = gmdate('Y-m-d', $now);

        $reports = [];
        foreach ($providers as $providerName => $providerConfig) {
            if (!is_string($providerName) || $providerName === 'local-null-provider' || !is_array($providerConfig)) {
                continue;
            }
            /** @var array<string, mixed> $typedProviderConfig */
            $typedProviderConfig = $providerConfig;

            $reports[] = $this->inspectProvider($providerName, $typedProviderConfig, $state, $today, $now, $runExternalProbes, $currentRunStartedAt);
        }

        usort($reports, $this->compare(...));

        return $reports;
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @param array<string, mixed> $state
     */
    private function inspectProvider(string $providerName, array $providerConfig, array $state, string $today, int $now, bool $runExternalProbes, ?int $currentRunStartedAt = null): ProviderCapacityReport
    {
        $enabled = ($providerConfig['enabled'] ?? false) === true;
        $budget = $this->configuredDailyBudget($providerConfig);
        $used = $this->providerUsage($state, $providerName, $today);
        $budgetRemaining = $budget > 0 ? max($budget - $used, 0) : null;
        $cooldownRemaining = $this->cooldownRemainingSeconds($providerConfig, $state, $providerName, $now, $currentRunStartedAt);
        $probe = !$runExternalProbes
            ? [
                'status' => 'not-configured',
                'probe_message' => 'External probe skipped during automatic routing.',
            ]
            : ($enabled
            ? $this->probeProvider($providerName, $providerConfig, $now)
            : [
                'status' => 'not-configured',
                'probe_message' => 'Probe skipped because provider is disabled.',
            ]);

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
    private function probeProvider(string $providerName, array $providerConfig, int $now): array
    {
        $command = $this->stringList($providerConfig['resource_command'] ?? []);
        if ($command === []) {
            return [
                'status' => 'not-configured',
                'probe_message' => 'No resource command configured.',
            ];
        }

        $workingDirectory = $this->configuredWorkingDirectory($providerConfig);
        $timeoutSeconds = $this->configuredTimeoutSeconds($providerConfig);
        $process = $this->processExecutor->execute($command, $workingDirectory, $timeoutSeconds);

        if (!$process->successful()) {
            $details = $this->probeDetailsFromOutput($providerName, $process->stdout, $process->stderr, $now);
            if ($details['external_metrics'] !== []) {
                return [
                    'status' => 'ok',
                    'external_remaining_ratio' => min(array_map(static fn (array $metric): float => $metric['remaining_ratio'], $details['external_metrics'])),
                    'external_reset_at' => $this->latestResetValue($details['external_metrics']),
                    'probe_command' => $command,
                    'probe_message' => $details['probe_message'] ?? sprintf('Parsed %d external limit(s).', count($details['external_metrics'])),
                    'external_metrics' => $details['external_metrics'],
                ];
            }
            if ($details['probe_message'] !== null) {
                return [
                    'status' => 'not-configured',
                    'probe_command' => $command,
                    'probe_message' => $details['probe_message'],
                ];
            }

            return [
                'status' => 'failed',
                'probe_command' => $command,
                'probe_message' => $this->failureMessage($process),
            ];
        }

        $details = $this->probeDetailsFromOutput($providerName, $process->stdout, $process->stderr, $now);
        if ($details['external_metrics'] === []) {
            if ($details['probe_message'] !== null) {
                return [
                    'status' => 'not-configured',
                    'probe_command' => $command,
                    'probe_message' => $details['probe_message'],
                ];
            }

            return [
                'status' => 'failed',
                'probe_command' => $command,
                'probe_message' => 'Probe command succeeded but returned no parseable capacity data.',
            ];
        }

        return [
            'status' => 'ok',
            'external_remaining_ratio' => min(array_map(static fn (array $metric): float => $metric['remaining_ratio'], $details['external_metrics'])),
            'external_reset_at' => $this->latestResetValue($details['external_metrics']),
            'probe_command' => $command,
            'probe_message' => $details['probe_message'] ?? sprintf('Parsed %d external limit(s).', count($details['external_metrics'])),
            'external_metrics' => $details['external_metrics'],
        ];
    }

    /**
     * @return array{
     *     probe_message: string|null,
     *     external_metrics: list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     * }
     */
    private function probeDetailsFromOutput(string $providerName, string $stdout, string $stderr, int $now): array
    {
        $metrics = $this->metricsFromOutput($providerName, $stdout, $stderr, $now);
        if ($metrics !== []) {
            return [
                'probe_message' => null,
                'external_metrics' => $metrics,
            ];
        }

        $usageLimitMetric = $this->usageLimitMetricFromOutput($providerName, $stdout, $stderr, $now);
        if ($usageLimitMetric !== null) {
            return [
                'probe_message' => $this->providerUsageSummary($providerName, $stdout, $stderr),
                'external_metrics' => [$usageLimitMetric],
            ];
        }

        return [
            'probe_message' => $this->providerUsageSummary($providerName, $stdout, $stderr),
            'external_metrics' => [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function providerUsage(array $state, string $providerName, string $today): int
    {
        $usage = $this->stateValue($state, 'providers.' . $providerName . '.usage.' . $today);

        return is_int($usage) ? $usage : 0;
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @param array<string, mixed> $state
     */
    private function cooldownRemainingSeconds(array $providerConfig, array $state, string $providerName, int $now, ?int $currentRunStartedAt = null): int
    {
        $cooldown = $this->configuredCooldownSeconds($providerConfig);
        $lastUsedAt = $this->stateValue($state, 'providers.' . $providerName . '.last_used_at');
        if ($cooldown < 1 || !is_int($lastUsedAt)) {
            return 0;
        }
        if ($currentRunStartedAt !== null && $lastUsedAt >= $currentRunStartedAt) {
            return 0;
        }

        return max(($lastUsedAt + $cooldown) - $now, 0);
    }

    /**
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function metricsFromOutput(string $providerName, string $stdout, string $stderr, int $now): array
    {
        $output = trim($stdout);
        if ($output !== '') {
            $decoded = json_decode($output, true);
            if (is_array($decoded)) {
                $metrics = $this->metricsFromJson($decoded, [], $now);
                if ($metrics !== []) {
                    return $metrics;
                }
            }
        }

        $metrics = [];
        foreach ([$this->trimmedOrNull($stdout), $this->trimmedOrNull($stderr)] as $textOutput) {
            if ($textOutput !== null) {
                foreach ($this->metricsFromText($providerName, $textOutput, $now) as $metric) {
                    $metrics[] = $metric;
                }
            }
        }

        return $this->uniqueMetrics($metrics);
    }

    /**
     * @return array{label: string, remaining_ratio: float, reset_at: int|null}|null
     */
    private function usageLimitMetricFromOutput(string $providerName, string $stdout, string $stderr, int $now): ?array
    {
        $combinedOutput = trim($stdout . "\n" . $stderr);
        if ($combinedOutput === '') {
            return null;
        }

        if ($providerName === 'codex' && preg_match('/hit your usage limit|usage limit/i', $combinedOutput) === 1) {
            $resetAt = null;
            if (preg_match('/try again at (?P<reset>.+?)(?:\\.|$)/i', $combinedOutput, $matches) === 1) {
                $resetAt = $this->parseResetValue($matches['reset'], $now);
            }

            return [
                'label' => 'codex',
                'remaining_ratio' => 0.0,
                'reset_at' => $resetAt,
            ];
        }

        return null;
    }

    private function providerUsageSummary(string $providerName, string $stdout, string $stderr): ?string
    {
        return match ($providerName) {
            'gemini' => $this->geminiUsageSummary($stdout),
            'copilot' => $this->copilotUsageSummary($stdout),
            'codex' => $this->codexUsageSummary($stdout, $stderr),
            default => null,
        };
    }

    private function geminiUsageSummary(string $stdout): ?string
    {
        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded)) {
            return null;
        }

        $stats = $this->associativeArray($decoded['stats'] ?? null);
        $models = $stats['models'] ?? null;
        if (!is_array($models)) {
            return null;
        }

        $parts = [];
        foreach ($models as $modelName => $modelStats) {
            if (!is_string($modelName) || !is_array($modelStats)) {
                continue;
            }
            $requests = $this->numericField($this->associativeArray($modelStats['api'] ?? null) ?? [], ['totalRequests']);
            $totalTokens = $this->numericField($this->associativeArray($modelStats['tokens'] ?? null) ?? [], ['total']);
            $modelPart = $modelName;
            if ($requests !== null) {
                $modelPart .= ' requests=' . (int) $requests;
            }
            if ($totalTokens !== null) {
                $modelPart .= ' tokens=' . (int) $totalTokens;
            }
            $parts[] = $modelPart;
        }

        if ($parts === []) {
            return null;
        }

        return 'Session stats: ' . implode('; ', array_slice($parts, 0, 3));
    }

    private function copilotUsageSummary(string $stdout): ?string
    {
        foreach ($this->jsonLines($stdout) as $payload) {
            $type = is_string($payload['type'] ?? null) ? $payload['type'] : null;
            if ($type !== 'result') {
                continue;
            }

            $usage = $this->associativeArray($payload['usage'] ?? null);
            if ($usage === null) {
                continue;
            }

            $premiumRequests = $this->numericField($usage, ['premiumRequests']);
            $sessionDurationMs = $this->numericField($usage, ['sessionDurationMs']);
            $totalApiDurationMs = $this->numericField($usage, ['totalApiDurationMs']);
            $parts = [];
            if ($premiumRequests !== null) {
                $parts[] = sprintf('premium_requests=%d', (int) $premiumRequests);
            }
            if ($sessionDurationMs !== null) {
                $parts[] = sprintf('session_ms=%d', (int) $sessionDurationMs);
            }
            if ($totalApiDurationMs !== null) {
                $parts[] = sprintf('api_ms=%d', (int) $totalApiDurationMs);
            }

            return $parts === [] ? null : 'Session usage: ' . implode(', ', $parts);
        }

        return null;
    }

    private function codexUsageSummary(string $stdout, string $stderr): ?string
    {
        foreach ($this->jsonLines($stdout) as $payload) {
            $type = is_string($payload['type'] ?? null) ? $payload['type'] : null;
            if ($type !== 'error') {
                continue;
            }

            $message = $payload['message'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }

        $combined = trim($stdout . "\n" . $stderr);

        return $combined !== '' ? $combined : null;
    }

    /**
     * @return list<array<string, mixed>>
        */
    private function jsonLines(string $text): array
    {
        $payloads = [];
        foreach (preg_split("/\\r\\n|\\n|\\r/", $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                continue;
            }
            /** @var array<string, mixed> $decoded */
            $payloads[] = $decoded;
        }

        return $payloads;
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $path
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function metricsFromJson(array $payload, array $path, int $now): array
    {
        $metrics = [];
        $metric = $this->metricFromArray($payload, $path, $now);
        if ($metric !== null) {
            $metrics[] = $metric;
        }

        foreach ($payload as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }
            foreach ($this->metricsFromJson($value, $this->appendPath($path, $key), $now) as $childMetric) {
                $metrics[] = $childMetric;
            }
        }

        return $this->uniqueMetrics($metrics);
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $path
     * @return array{label: string, remaining_ratio: float, reset_at: int|null}|null
     */
    private function metricFromArray(array $payload, array $path, int $now): ?array
    {
        $remainingRatio = $this->extractRemainingRatio($payload);
        if ($remainingRatio === null) {
            return null;
        }

        $label = $this->metricLabel($payload, $path);
        $resetAt = $this->extractResetAt($payload, $now);

        return [
            'label' => $label,
            'remaining_ratio' => $this->clampRatio($remainingRatio),
            'reset_at' => $resetAt,
        ];
    }

    /**
     * @return list<array{label: string, remaining_ratio: float, reset_at: int|null}>
     */
    private function metricsFromText(string $providerName, string $output, int $now): array
    {
        preg_match_all('/^(?P<label>.+?)\s+(?P<percent>\d+(?:\.\d+)?)%\s*(?P<mode>left|remaining|used)?(?:.*?resets?\s+(?P<reset>.+))?$/mi', $output, $matches, PREG_SET_ORDER);

        $metrics = [];
        foreach ($matches as $match) {
            $label = trim($match['label'], " \t\n\r\0\x0B:-");
            $percent = (float) $match['percent'];
            $mode = strtolower(trim((string) ($match['mode'] ?? '')));
            $remainingRatio = $mode === 'used' ? (100.0 - $percent) / 100.0 : $percent / 100.0;
            $metrics[] = [
                'label' => $label !== '' ? $label : $providerName,
                'remaining_ratio' => $this->clampRatio($remainingRatio),
                'reset_at' => $this->parseResetValue($match['reset'] ?? null, $now),
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
            $key = json_encode([$metric['label'], $metric['remaining_ratio'], $metric['reset_at']]);
            if ($key === false) {
                continue;
            }
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
    private function extractResetAt(array $payload, int $now): ?int
    {
        foreach (['reset_at', 'resets_at', 'next_reset_at', 'resetAt', 'nextResetAt'] as $key) {
            $resetAt = $this->parseResetValue($payload[$key] ?? null, $now);
            if ($resetAt !== null) {
                return $resetAt;
            }
        }

        foreach (['reset_in_seconds', 'resets_in_seconds', 'seconds_until_reset', 'resetInSeconds'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                return $now + (int) $value;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $path
     */
    private function metricLabel(array $payload, array $path): string
    {
        foreach (['label', 'name', 'metric', 'window', 'period', 'plan'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if ($path !== []) {
            return $path[array_key_last($path)];
        }

        return 'limit';
    }

    /**
     * @param list<array{label: string, remaining_ratio: float, reset_at: int|null}> $metrics
     * @return list<int>
     */
    private function collectResetValues(array $metrics): array
    {
        $resetValues = [];
        foreach ($metrics as $metric) {
            if ($metric['reset_at'] !== null) {
                $resetValues[] = $metric['reset_at'];
            }
        }

        return $resetValues;
    }

    /**
     * @param list<array{label: string, remaining_ratio: float, reset_at: int|null}> $metrics
     */
    private function latestResetValue(array $metrics): ?int
    {
        $resetValues = $this->collectResetValues($metrics);

        return $resetValues === [] ? null : max($resetValues);
    }

    private function trimmedOrNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param list<string> $path
     * @return list<string>
     */
    private function appendPath(array $path, string $segment): array
    {
        $path[] = $segment;

        return $path;
    }

    /**
     * @param array<string, mixed> $providerConfig
     */
    private function configuredDailyBudget(array $providerConfig): int
    {
        return $this->positiveInt($providerConfig['daily_budget'] ?? null);
    }

    /**
     * @param array<string, mixed> $providerConfig
     */
    private function configuredCooldownSeconds(array $providerConfig): int
    {
        return $this->positiveInt($providerConfig['cooldown_seconds'] ?? null);
    }

    /**
     * @param array<string, mixed> $providerConfig
     */
    private function configuredWorkingDirectory(array $providerConfig): string
    {
        return is_string($providerConfig['working_directory'] ?? null) ? $providerConfig['working_directory'] : dirname(__DIR__, 2);
    }

    /**
     * @param array<string, mixed> $providerConfig
     */
    private function configuredTimeoutSeconds(array $providerConfig): int
    {
        return $this->positiveInt($providerConfig['timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS);
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

    private function parseResetValue(mixed $value, int $now): ?int
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
            return $now + $relative;
        }

        try {
            $timezone = new DateTimeZone('UTC');
            $date = new DateTimeImmutable($trimmed, $timezone);

            return $date->getTimestamp();
        } catch (Throwable) {
            return null;
        }
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
     * @return array<string, mixed>|null
     */
    private function associativeArray(mixed $value): ?array
    {
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }

        $typed = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }
            $typed[$key] = $item;
        }

        return $typed;
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
