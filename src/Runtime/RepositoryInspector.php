<?php

declare(strict_types=1);

namespace HousekeepingAgentCron\Runtime;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class RepositoryInspector
{
    public function __construct(private int $maxFiles = 100)
    {
    }

    /**
     * @return array{documentation_files: list<string>, todo_files: list<string>, key_files: list<string>, generated_at: int}
     */
    public function discover(string $repositoryRoot): array
    {
        if (!is_dir($repositoryRoot)) {
            return [
                'documentation_files' => [],
                'todo_files' => [],
                'key_files' => [],
                'generated_at' => time(),
            ];
        }

        $documentationFiles = [];
        $todoFiles = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repositoryRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $relativePath = $this->relativePath($repositoryRoot, $fileInfo->getPathname());
            if ($this->isIgnored($relativePath)) {
                continue;
            }

            if ($this->isDocumentationFile($relativePath, $fileInfo)) {
                $documentationFiles[] = $relativePath;
            }
            if ($this->isTodoFile($relativePath, $fileInfo)) {
                $todoFiles[] = $relativePath;
            }
        }

        $documentationFiles = $this->normalize($documentationFiles);
        $todoFiles = $this->normalize($todoFiles);

        return [
            'documentation_files' => $documentationFiles,
            'todo_files' => $todoFiles,
            'key_files' => $this->normalize($this->keyFiles($repositoryRoot)),
            'generated_at' => time(),
        ];
    }

    /**
     * @param list<string> $relativePaths
     * @return array<string, string>
     */
    public function readFiles(string $repositoryRoot, array $relativePaths): array
    {
        $documents = [];
        foreach ($relativePaths as $relativePath) {
            $path = $repositoryRoot . '/' . ltrim($relativePath, '/');
            if (!is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $documents[$relativePath] = $contents;
        }

        return $documents;
    }

    private function relativePath(string $repositoryRoot, string $path): string
    {
        return ltrim(substr($path, strlen(rtrim($repositoryRoot, '/'))), '/');
    }

    private function isIgnored(string $relativePath): bool
    {
        foreach (['.git/', 'vendor/', 'var/'] as $ignoredPrefix) {
            if (str_starts_with($relativePath, $ignoredPrefix)) {
                return true;
            }
        }

        return false;
    }

    private function isDocumentationFile(string $relativePath, SplFileInfo $fileInfo): bool
    {
        $basename = strtolower($fileInfo->getBasename());
        $extension = strtolower($fileInfo->getExtension());
        $directory = str_contains($relativePath, '/') ? dirname($relativePath) : '.';

        if (in_array($basename, ['readme', 'readme.md', 'changelog', 'changelog.md', 'contributing.md', 'license', 'license.md'], true)) {
            return true;
        }

        if ($extension === 'md' && ($directory === '.' || str_starts_with($relativePath, 'docs/'))) {
            return true;
        }

        return false;
    }

    private function isTodoFile(string $relativePath, SplFileInfo $fileInfo): bool
    {
        $basename = strtolower($fileInfo->getBasename());
        if (!str_contains($basename, 'todo') && !str_contains($basename, 'backlog')) {
            return false;
        }

        if (str_starts_with($relativePath, 'docs/')) {
            return true;
        }

        $extension = strtolower($fileInfo->getExtension());

        return $extension === '' || in_array($extension, ['md', 'txt'], true);
    }

    /**
     * @return list<string>
     */
    private function keyFiles(string $repositoryRoot): array
    {
        $paths = [];
        foreach (['composer.json', 'config/tasks.php', 'bin/agent-cron', 'README.md', 'TODO.md'] as $relativePath) {
            if (is_file($repositoryRoot . '/' . $relativePath)) {
                $paths[] = $relativePath;
            }
        }

        return $paths;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalize(array $paths): array
    {
        $paths = array_values(array_unique($paths));
        sort($paths);

        return array_slice($paths, 0, $this->maxFiles);
    }
}
