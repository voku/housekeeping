<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\ProviderOutputNormalizer;
use PHPUnit\Framework\TestCase;

final class ProviderOutputNormalizerTest extends TestCase
{
    public function testNormalizeReturnsOriginalContextWhenNoStructuredOutputIsPresent(): void
    {
        $context = [
            'provider' => 'codex',
            'stderr' => 'warning',
        ];

        $normalized = (new ProviderOutputNormalizer())->normalize($context);

        self::assertSame($context, $normalized);
        self::assertArrayNotHasKey('provider_output', $normalized);
    }

    public function testNormalizePrefersExistingProviderOutputAndMergesStdoutStructuredData(): void
    {
        $stdout = json_encode([
            'summary' => 'stdout summary',
            'summaries' => ['stdout point'],
            'patches' => [
                [
                    'summary' => 'stdout patch',
                    'path' => 'docs/guide.md',
                ],
            ],
            'metadata' => [
                'second' => 'value',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($stdout);

        $normalized = (new ProviderOutputNormalizer())->normalize([
            'summary' => 'top level summary',
            'summaries' => ['top level point'],
            'patches' => [['summary' => 'top level patch', 'path' => 'ignored.md']],
            'metadata' => ['ignored' => true],
            'provider_output' => [
                'summary' => 'existing summary',
                'summaries' => ['existing point'],
                'patches' => [
                    [
                        'summary' => 'existing patch',
                        'paths' => ['README.md'],
                        'diff_present' => false,
                    ],
                ],
                'metadata' => [
                    'first' => 'value',
                ],
            ],
            'stdout' => $stdout,
        ]);

        $providerOutput = $this->providerOutput($normalized);
        self::assertSame('existing summary', $providerOutput['summary']);
        self::assertSame(['existing point', 'stdout point'], $providerOutput['summaries']);
        self::assertCount(2, $providerOutput['patches']);
        self::assertSame('README.md', $providerOutput['patches'][0]['paths'][0] ?? null);
        self::assertSame('docs/guide.md', $providerOutput['patches'][1]['paths'][0] ?? null);
        self::assertSame(
            ['first' => 'value', 'second' => 'value'],
            $providerOutput['metadata'],
        );
    }

    public function testNormalizeDerivesSummaryFromSummaries(): void
    {
        $normalized = (new ProviderOutputNormalizer())->normalize([
            'summaries' => ['first point', 'second point'],
        ]);

        $providerOutput = $this->providerOutput($normalized);
        self::assertSame('first point', $providerOutput['summary']);
        self::assertSame(['first point', 'second point'], $providerOutput['summaries']);
    }

    public function testNormalizeBackfillsSummariesFromSummaryAndTrimsPlainStdout(): void
    {
        $fromSummary = (new ProviderOutputNormalizer())->normalize([
            'summary' => '  concise summary  ',
        ]);
        $summaryOutput = $this->providerOutput($fromSummary);
        self::assertSame('concise summary', $summaryOutput['summary']);
        self::assertSame(['concise summary'], $summaryOutput['summaries']);

        $fromStdout = (new ProviderOutputNormalizer())->normalize([
            'stdout' => '  raw guidance  ',
        ]);
        $stdoutOutput = $this->providerOutput($fromStdout);
        self::assertSame('raw guidance', $stdoutOutput['summary']);
        self::assertSame(['raw guidance'], $stdoutOutput['summaries']);
    }

    public function testNormalizeExtractsMultiplePatchesAndMetadataFromStructuredStdout(): void
    {
        $stdout = json_encode([
            'patches' => [
                [
                    'summary' => 'Patch one',
                    'path' => 'README.md',
                ],
                [
                    'summary' => 'Patch two',
                    'file' => 'docs/guide.md',
                ],
            ],
            'metadata' => [
                'alpha' => 1,
                'beta' => 2,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($stdout);

        $normalized = (new ProviderOutputNormalizer())->normalize([
            'stdout' => $stdout,
        ]);

        $providerOutput = $this->providerOutput($normalized);
        self::assertCount(2, $providerOutput['patches']);
        self::assertSame(['alpha' => 1, 'beta' => 2], $providerOutput['metadata']);
    }

    public function testNormalizeKeepsDiffOnlyStdoutAsPatchMetadata(): void
    {
        $normalized = (new ProviderOutputNormalizer())->normalize([
            'stdout' => <<<'DIFF'
```diff
--- a/README.md
+++ b/README.md
@@
-Old
+New
```
DIFF,
        ]);

        $providerOutput = $this->providerOutput($normalized);
        self::assertNull($providerOutput['summary']);
        self::assertSame([], $providerOutput['summaries']);
        self::assertSame(['README.md'], $providerOutput['patches'][0]['paths'] ?? null);
        self::assertTrue($providerOutput['patches'][0]['diff_present']);
    }

    public function testNormalizeDropsEmptyPatchPayloads(): void
    {
        $stdout = json_encode([
            'patches' => [
                [
                    'summary' => '  ',
                    'paths' => [],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($stdout);

        $normalized = (new ProviderOutputNormalizer())->normalize([
            'stdout' => $stdout,
            'provider' => 'codex',
        ]);

        self::assertSame(
            [
                'stdout' => $stdout,
                'provider' => 'codex',
                'provider_output' => [
                    'summary' => $stdout,
                    'summaries' => [$stdout],
                    'patches' => [],
                    'metadata' => [],
                ],
            ],
            $normalized,
        );
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{
     *     summary: string|null,
     *     summaries: list<string>,
     *     patches: list<array{summary: string|null, paths: list<string>, diff_present: bool}>,
     *     metadata: array<string, mixed>
     * }
     */
    private function providerOutput(array $normalized): array
    {
        $providerOutput = $normalized['provider_output'] ?? null;
        self::assertIsArray($providerOutput);

        /** @var array{
         *     summary: string|null,
         *     summaries: list<string>,
         *     patches: list<array{summary: string|null, paths: list<string>, diff_present: bool}>,
         *     metadata: array<string, mixed>
         * } $providerOutput
         */
        return $providerOutput;
    }
}
