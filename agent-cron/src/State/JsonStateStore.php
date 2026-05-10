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
            mkdir($dir, 0775, true);
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

        return $state;
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
