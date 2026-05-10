<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\State;

use HousekeepingAgentCron\Contract\StateStore;
use RuntimeException;

final readonly class JsonStateStore implements StateStore
{
    public function __construct(private string $path)
    {
        $dir = dirname($this->path);
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
                $message = 'Unable to create state directory: ' . $dir . ' for ' . $this->path;
                if (is_string($mkdirError) && $mkdirError !== '') {
                    $message .= ' (' . $mkdirError . ')';
                }

                throw new RuntimeException($message);
            }
        }
    }

    public function load(): array
    {
        if (!is_file($this->path)) {
            return [
                'tasks' => [],
                'providers' => [],
                'runs' => [],
            ];
        }

        $json = file_get_contents($this->path);
        if ($json === false) {
            throw new RuntimeException('Unable to read state file: ' . $this->path);
        }

        $state = json_decode($json, true);
        if (!is_array($state)) {
            throw new RuntimeException('State file is not valid JSON: ' . $this->path);
        }
        /** @var array<string, mixed> $typedState */
        $typedState = $state;

        return $typedState;
    }

    public function save(array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to encode state JSON.');
        }

        $tmp = $this->path . '.tmp';
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write state file: ' . $tmp);
        }
        if (!rename($tmp, $this->path)) {
            throw new RuntimeException('Unable to move state file into place: ' . $this->path);
        }
    }
}
