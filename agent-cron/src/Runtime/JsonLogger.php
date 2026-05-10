<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use RuntimeException;

final readonly class JsonLogger
{
    public function __construct(private string $logFile)
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            $mkdirError = null;
            set_error_handler(static function (int $severity, string $message) use (&$mkdirError): bool {
                $mkdirError = $message;

                return true;
            });

            try {
                $created = mkdir($dir, 0775, true);
            } finally {
                restore_error_handler();
            }

            if (!$created && !is_dir($dir)) {
                $message = 'Unable to create log directory: ' . $dir . ' for ' . $this->logFile;
                if (is_string($mkdirError) && $mkdirError !== '') {
                    $message .= ' (' . $mkdirError . ')';
                }

                throw new RuntimeException($message);
            }
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
