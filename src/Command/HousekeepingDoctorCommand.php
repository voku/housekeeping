<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Command;

use HousekeepingAgentCron\Runtime\ApplicationFactory;
use HousekeepingAgentCron\Runtime\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

#[AsCommand(name: 'housekeeping:doctor', description: 'Validate housekeeping config, paths, and enabled providers.')]
final class HousekeepingDoctorCommand extends Command
{
    public function __construct(
        private readonly string $configFile,
        private readonly ApplicationFactory $factory = new ApplicationFactory(),
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print health checks as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->factory->loadConfig($this->configFile);
            $checks = [
                $this->pathCheck('state', dirname($this->statePath($config))),
                $this->pathCheck('lock', $this->factory->lockDir($config)),
                $this->pathCheck('logs', $this->logsPath($config)),
            ];

            $this->factory->stateStore($config)->load();
            $this->factory->logger($config);

            foreach ($this->taskFileChecks($config) as $check) {
                $checks[] = $check;
            }
            foreach ($this->providerChecks($config) as $check) {
                $checks[] = $check;
            }

            $successful = !in_array(false, array_column($checks, 'ok'), true);
            if ($input->getOption('json') === true) {
                $json = json_encode([
                    'ok' => $successful,
                    'checks' => $checks,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    $output->writeln('<error>Unable to encode doctor output.</error>');

                    return ExitCode::TASK_FAILED;
                }
                $output->writeln($json);

                return $successful ? ExitCode::SUCCESS : ExitCode::INVALID_CONFIG;
            }

            $io = new SymfonyStyle($input, $output);
            $io->table(
                ['Check', 'Status', 'Message'],
                array_map(static fn (array $check): array => [
                    $check['name'],
                    $check['ok'] ? 'ok' : 'fail',
                    $check['message'],
                ], $checks),
            );
            if ($successful) {
                $io->success('Housekeeping config looks healthy.');
            } else {
                $io->error('Housekeeping config has failing checks.');
            }

            return $successful ? ExitCode::SUCCESS : ExitCode::INVALID_CONFIG;
        } catch (Throwable $throwable) {
            $output->writeln($throwable->getMessage());

            return ExitCode::INVALID_CONFIG;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{name: string, ok: bool, message: string}>
     */
    private function providerChecks(array $config): array
    {
        $providers = $config['providers'] ?? null;
        if (!is_array($providers)) {
            return [['name' => 'providers', 'ok' => false, 'message' => 'Config key "providers" must be an array.']];
        }

        $checks = [];
        foreach ($providers as $providerName => $providerConfig) {
            if (!is_string($providerName) || $providerName === 'local-null-provider' || !is_array($providerConfig)) {
                continue;
            }

            if (($providerConfig['enabled'] ?? false) !== true) {
                continue;
            }

            $configuredCommand = $providerConfig['command'] ?? [];
            $configuredCommand = is_array($configuredCommand) ? $configuredCommand : [];
            $hasConfiguredCommand = $this->hasConfiguredCommand($configuredCommand);
            $checks[] = [
                'name' => 'provider:' . $providerName,
                'ok' => $hasConfiguredCommand,
                'message' => $hasConfiguredCommand
                    ? 'Enabled provider command is configured.'
                    : 'Enabled provider command is missing.',
            ];
        }

        return $checks;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{name: string, ok: bool, message: string}>
     */
    private function taskFileChecks(array $config): array
    {
        $tasks = $config['tasks'] ?? null;
        if (!is_array($tasks)) {
            return [['name' => 'tasks', 'ok' => false, 'message' => 'Config key "tasks" must be an array.']];
        }

        $checks = [];
        $repositoryRoot = $this->repositoryRoot($config);
        foreach ($tasks as $taskName => $taskConfig) {
            if (!is_string($taskName) || !is_array($taskConfig) || ($taskConfig['enabled'] ?? false) !== true) {
                continue;
            }

            $configuredFiles = [];
            foreach (['input_files', 'context_files'] as $key) {
                foreach ($this->configuredTaskFiles($taskConfig[$key] ?? [], $repositoryRoot) as $path) {
                    $configuredFiles[] = ['group' => $key, 'path' => $path];
                }
            }

            if ($configuredFiles === []) {
                continue;
            }

            $missingFiles = [];
            foreach ($configuredFiles as $configuredFile) {
                if (!is_file($configuredFile['path'])) {
                    $missingFiles[] = $configuredFile['group'] . '=' . $configuredFile['path'];
                }
            }

            $checks[] = [
                'name' => 'task:' . $taskName . ':files',
                'ok' => $missingFiles === [],
                'message' => $missingFiles === []
                    ? 'Configured input/context files exist.'
                    : 'Missing configured input/context files: ' . implode(', ', $missingFiles),
            ];
        }

        return $checks;
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function pathCheck(string $label, string $path): array
    {
        try {
            $this->filesystem->mkdir($path);
        } catch (Throwable $throwable) {
            return [
                'name' => $label,
                'ok' => false,
                'message' => $throwable->getMessage(),
            ];
        }

        return [
            'name' => $label,
            'ok' => is_dir($path) && is_writable($path),
            'message' => is_dir($path) && is_writable($path)
                ? 'Path is writable: ' . $path
                : 'Path is not writable: ' . $path,
        ];
    }

    /**
     * @param array<int|string, mixed> $configuredCommand
     */
    private function hasConfiguredCommand(array $configuredCommand): bool
    {
        foreach ($configuredCommand as $item) {
            if (is_string($item) && $item !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function configuredTaskFiles(mixed $value, string $repositoryRoot): array
    {
        if (!is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $paths[] = str_starts_with($path, '/')
                ? $path
                : rtrim($repositoryRoot, '/') . '/' . ltrim($path, '/');
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function statePath(array $config): string
    {
        $paths = $config['paths'] ?? null;
        if (is_array($paths) && is_string($paths['state'] ?? null)) {
            return $paths['state'];
        }

        return dirname(__DIR__, 2) . '/var/state/state.json';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function logsPath(array $config): string
    {
        $paths = $config['paths'] ?? null;
        if (is_array($paths) && is_string($paths['logs'] ?? null)) {
            return $paths['logs'];
        }

        return dirname(__DIR__, 2) . '/var/logs';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function repositoryRoot(array $config): string
    {
        $paths = $config['paths'] ?? null;
        if (is_array($paths) && is_string($paths['repository_root'] ?? null) && $paths['repository_root'] !== '') {
            return $paths['repository_root'];
        }

        return dirname(__DIR__, 2);
    }
}
