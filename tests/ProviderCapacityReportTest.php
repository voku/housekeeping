<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Tests;

use HousekeepingAgentCron\Runtime\ProviderCapacityReport;
use PHPUnit\Framework\TestCase;

final class ProviderCapacityReportTest extends TestCase
{
    public function testToArrayIncludesAllExpectedFields(): void
    {
        $report = new ProviderCapacityReport(
            'gemini',
            true,
            'ready',
            20,
            3,
            17,
            0,
            0.9,
            1700000000,
            [PHP_BINARY, '-r', 'echo "ok";'],
            'Parsed 1 external limit.',
            [
                [
                    'label' => 'grüße',
                    'remaining_ratio' => 0.9,
                    'reset_at' => 1700000000,
                ],
            ],
        );

        self::assertSame([
            'provider' => 'gemini',
            'enabled' => true,
            'status' => 'ready',
            'internal_budget' => 20,
            'internal_used' => 3,
            'internal_budget_remaining' => 17,
            'cooldown_remaining_seconds' => 0,
            'external_remaining_ratio' => 0.9,
            'external_reset_at' => 1700000000,
            'probe_command' => [PHP_BINARY, '-r', 'echo "ok";'],
            'probe_message' => 'Parsed 1 external limit.',
            'external_metrics' => [
                [
                    'label' => 'grüße',
                    'remaining_ratio' => 0.9,
                    'reset_at' => 1700000000,
                ],
            ],
        ], $report->toArray());
    }
}
