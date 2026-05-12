<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

final readonly class ProviderOutputNormalizer
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function normalize(array $context): array
    {
        $normalizedOutput = $this->normalizeOutput($context);
        if ($normalizedOutput === null) {
            return $context;
        }

        $context['provider_output'] = $normalizedOutput;

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{
     *     summary: string|null,
     *     summaries: list<string>,
     *     patches: list<array{summary: string|null, paths: list<string>, diff_present: bool}>,
     *     metadata: array<string, mixed>
     * }|null
     */
    private function normalizeOutput(array $context): ?array
    {
        $existing = is_array($context['provider_output'] ?? null) ? $context['provider_output'] : [];

        $summary = $this->trimmedString($existing['summary'] ?? $context['summary'] ?? null);
        $summaries = $this->stringList($existing['summaries'] ?? $context['summaries'] ?? []);
        $patches = $this->patchMetadataList($existing['patches'] ?? $context['patches'] ?? []);
        $metadata = $this->associativeArray($existing['metadata'] ?? $context['metadata'] ?? []) ?? [];

        $stdout = $this->trimmedString($context['stdout'] ?? null);
        if ($stdout !== null) {
            $structured = $this->structuredOutputFromText($stdout);
            $summary ??= $structured['summary'];
            $summaries = $this->mergeStrings($summaries, $structured['summaries']);
            $patches = $this->mergePatches($patches, $structured['patches']);
            $metadata = $this->mergeMetadata($metadata, $structured['metadata']);
        }

        if ($summary === null && $summaries !== []) {
            $summary = $summaries[0];
        }
        if ($summary !== null && $summaries === []) {
            $summaries = [$summary];
        }
        if ($summary === null && $stdout !== null && $patches === []) {
            $summary = $stdout;
            $summaries = [$stdout];
        }

        if ($summary === null && $summaries === [] && $patches === [] && $metadata === []) {
            return null;
        }

        return [
            'summary' => $summary,
            'summaries' => $summaries,
            'patches' => $patches,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array{
     *     summary: string|null,
     *     summaries: list<string>,
     *     patches: list<array{summary: string|null, paths: list<string>, diff_present: bool}>,
     *     metadata: array<string, mixed>
     * }
     */
    private function structuredOutputFromText(string $text): array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $this->structuredOutputFromPayload($decoded);
        }

        $patches = $this->patchesFromText($text);
        $summary = $this->summaryWithoutDiffs($text);

        return [
            'summary' => $summary,
            'summaries' => $summary === null ? [] : [$summary],
            'patches' => $patches,
            'metadata' => [],
        ];
    }

    /**
     * @param array<mixed> $payload
     * @return array{
     *     summary: string|null,
     *     summaries: list<string>,
     *     patches: list<array{summary: string|null, paths: list<string>, diff_present: bool}>,
     *     metadata: array<string, mixed>
     * }
     */
    private function structuredOutputFromPayload(array $payload): array
    {
        if (array_is_list($payload)) {
            $patches = $this->patchMetadataList($payload);
            $summaries = $patches === [] ? $this->stringList($payload) : [];

            return [
                'summary' => $summaries[0] ?? null,
                'summaries' => $summaries,
                'patches' => $patches,
                'metadata' => [],
            ];
        }

        $summary = null;
        foreach (['summary', 'message', 'result', 'overview', 'analysis'] as $key) {
            $summary = $this->trimmedString($payload[$key] ?? null);
            if ($summary !== null) {
                break;
            }
        }

        $summaries = [];
        foreach (['summaries', 'summary_points', 'highlights', 'guidance'] as $key) {
            $summaries = $this->mergeStrings($summaries, $this->stringList($payload[$key] ?? []));
        }

        $patches = [];
        foreach (['patches', 'patch_suggestions', 'changes', 'diffs'] as $key) {
            $patches = $this->mergePatches($patches, $this->patchMetadataList($payload[$key] ?? []));
        }
        foreach (['patch', 'diff', 'unified_diff'] as $key) {
            $patch = $this->patchMetadataFromValue($payload[$key] ?? null);
            if ($patch !== null) {
                $patches = $this->mergePatches($patches, [$patch]);
            }
        }

        $metadata = $this->associativeArray($payload['metadata'] ?? $payload['context'] ?? []) ?? [];

        return [
            'summary' => $summary,
            'summaries' => $summaries,
            'patches' => $patches,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return list<array{summary: string|null, paths: list<string>, diff_present: bool}>
     */
    private function patchesFromText(string $text): array
    {
        preg_match_all('/```diff\s*(.*?)```/si', $text, $matches);

        $patches = [];
        /** @var list<string> $diffBlocks */
        $diffBlocks = $matches[1];
        foreach ($diffBlocks as $diffBlock) {
            $patch = $this->patchMetadataFromValue($diffBlock);
            if ($patch !== null) {
                $patches = $this->mergePatches($patches, [$patch]);
            }
        }

        if ($patches === [] && $this->looksLikeDiff($text)) {
            $patch = $this->patchMetadataFromValue($text);
            if ($patch !== null) {
                $patches[] = $patch;
            }
        }

        return $patches;
    }

    private function summaryWithoutDiffs(string $text): ?string
    {
        $summary = trim((string) preg_replace('/```diff\s*.*?```/si', '', $text));

        return $summary === '' || $this->looksLikeDiff($summary) ? null : $summary;
    }

    /**
     * @param mixed $value
     * @return array{summary: string|null, paths: list<string>, diff_present: bool}|null
     */
    private function patchMetadataFromValue(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !$this->looksLikeDiff($value)) {
                return null;
            }

            return [
                'summary' => null,
                'paths' => $this->pathsFromDiff($value),
                'diff_present' => true,
            ];
        }

        if (!is_array($value)) {
            return null;
        }

        $summary = null;
        foreach (['summary', 'description', 'title', 'message'] as $key) {
            $summary = $this->trimmedString($value[$key] ?? null);
            if ($summary !== null) {
                break;
            }
        }

        $paths = $this->stringList($value['paths'] ?? $value['files'] ?? []);
        foreach (['path', 'file', 'file_path', 'target'] as $key) {
            $path = $this->trimmedString($value[$key] ?? null);
            if ($path !== null) {
                $paths[] = $path;
            }
        }

        $diffText = null;
        foreach (['diff', 'patch', 'unified_diff'] as $key) {
            $diffText = $this->trimmedString($value[$key] ?? null);
            if ($diffText !== null) {
                $paths = array_merge($paths, $this->pathsFromDiff($diffText));
                break;
            }
        }

        $paths = array_values(array_unique(array_filter($paths, static fn (string $path): bool => $path !== '')));
        if ($summary === null && $paths === [] && $diffText === null) {
            return null;
        }

        return [
            'summary' => $summary,
            'paths' => $paths,
            'diff_present' => $diffText !== null,
        ];
    }

    /**
     * @param mixed $value
     * @return list<array{summary: string|null, paths: list<string>, diff_present: bool}>
     */
    private function patchMetadataList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $patches = [];
        foreach ($value as $item) {
            $patch = $this->patchMetadataFromValue($item);
            if ($patch !== null) {
                $patches = $this->mergePatches($patches, [$patch]);
            }
        }

        return $patches;
    }

    private function looksLikeDiff(string $text): bool
    {
        return str_contains($text, 'diff --git ')
            || str_contains($text, '--- ')
            || str_contains($text, '+++ ');
    }

    /**
     * @return list<string>
     */
    private function pathsFromDiff(string $diff): array
    {
        $diff = str_replace(["\r\n", "\r"], "\n", $diff);

        preg_match_all('/^(?:\+\+\+|---)\s+(?:a\/|b\/)?(?P<path>\S+)$/m', $diff, $matches);

        $paths = [];
        /** @var list<non-empty-string> $matchedPaths */
        $matchedPaths = $matches['path'];
        foreach ($matchedPaths as $path) {
            if ($path === '/dev/null') {
                continue;
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
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

        $strings = [];
        foreach ($value as $item) {
            $string = $this->trimmedString($item);
            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
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
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>
     */
    private function mergeStrings(array $left, array $right): array
    {
        return array_values(array_unique([...$left, ...$right]));
    }

    /**
     * @param list<array{summary: string|null, paths: list<string>, diff_present: bool}> $left
     * @param list<array{summary: string|null, paths: list<string>, diff_present: bool}> $right
     * @return list<array{summary: string|null, paths: list<string>, diff_present: bool}>
     */
    private function mergePatches(array $left, array $right): array
    {
        $merged = [];
        foreach ([...$left, ...$right] as $patch) {
            $key = json_encode($patch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($key)) {
                continue;
            }

            $merged[$key] = $patch;
        }

        return array_values($merged);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function mergeMetadata(array $left, array $right): array
    {
        if ($left === []) {
            return $right;
        }
        if ($right === []) {
            return $left;
        }

        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive($left, $right);

        return $merged;
    }

    private function trimmedString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
