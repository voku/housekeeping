<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use RuntimeException;

final class ConfigFileResolver
{
    /**
     * @param list<string> $argv
     * @return array{config_file: string, argv: list<string>}
     */
    public function resolve(array $argv, string $defaultConfigFile, ?string $environmentConfigFile = null): array
    {
        $sanitizedArgv = [];
        $configFile = $environmentConfigFile !== null && $environmentConfigFile !== '' ? $environmentConfigFile : $defaultConfigFile;
        $argc = count($argv);

        for ($index = 0; $index < $argc; $index++) {
            $argument = $argv[$index];

            if ($argument === '--config') {
                $nextIndex = $index + 1;
                $value = $argv[$nextIndex] ?? null;
                if (!is_string($value) || $value === '') {
                    throw new RuntimeException('The --config option requires a non-empty file path.');
                }
                $configFile = $value;
                $index++;

                continue;
            }

            if (str_starts_with($argument, '--config=')) {
                $value = substr($argument, 9);
                if ($value === '') {
                    throw new RuntimeException('The --config option requires a non-empty file path.');
                }
                $configFile = $value;

                continue;
            }

            $sanitizedArgv[] = $argument;
        }

        return [
            'config_file' => $configFile,
            'argv' => $sanitizedArgv,
        ];
    }
}
