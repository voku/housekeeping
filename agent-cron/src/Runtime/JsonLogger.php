<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

final readonly class JsonLogger
{
    public function __construct(private string $logFile)
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $event, array $context = []): void
    {
        $record = [
            'ts' => gmdate(DATE_ATOM),
            'level' => $level,
            'event' => $event,
            'context' => $context,
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            $line = '{"ts":"' . gmdate(DATE_ATOM) . '","level":"error","event":"log_encode_failed","context":{}}';
        }
        file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
