<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use Symfony\Component\Console\Output\OutputInterface;

final class TimestampedConsoleOutput
{
    public static function write(OutputInterface $output, string $message): void
    {
        $output->writeln(self::format($message));
    }

    public static function format(string $message): string
    {
        $timestamp = date(DATE_ATOM);
        $lines = preg_split('/\R/u', $message);
        if (!is_array($lines) || $lines === []) {
            $lines = [''];
        }

        return implode(PHP_EOL, array_map(
            static fn (string $line): string => sprintf('[%s] %s', $timestamp, $line),
            $lines,
        ));
    }
}
