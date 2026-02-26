<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ExtensionInstallerService
{
    protected string $composerPath;

    public function __construct()
    {
        $this->composerPath = base_path('composer.json');
    }

    /**
     * Add a repository and requirement to composer.json
     */
    public function addPackage(string $packageName, string $repositoryUrl): bool
    {
        // Validation: If it's a relative path starting with .., check if it exists relative to base_path
        $absoluteRepoPath = $repositoryUrl;
        if (str_starts_with($repositoryUrl, '..')) {
            $absoluteRepoPath = realpath(base_path($repositoryUrl));
        }

        if (!$absoluteRepoPath || !File::isDirectory($absoluteRepoPath)) {
            Log::error("Extension path does not exist: {$repositoryUrl} (Resolved to: " . ($absoluteRepoPath ?: 'FALSE') . ")");
            return false;
        }

        $composer = $this->getComposerData();

        // 1. Add Repository
        $repositories = $composer['repositories'] ?? [];
        $repoExists = false;
        foreach ($repositories as $repo) {
            if (($repo['url'] ?? '') === $repositoryUrl) {
                $repoExists = true;
                break;
            }
        }

        if (!$repoExists) {
            $repositories[] = [
                'type' => 'path',
                'url' => $repositoryUrl,
                'options' => [
                    'symlink' => false,
                ],
            ];
            $composer['repositories'] = $repositories;
        }

        // 2. Add Requirement
        $composer['require'][$packageName] = '@dev';

        return $this->saveComposerData($composer);
    }

    /**
     * Remove a package from composer.json
     */
    public function removePackage(string $packageName): bool
    {
        $composer = $this->getComposerData();

        if (isset($composer['require'][$packageName])) {
            unset($composer['require'][$packageName]);
            return $this->saveComposerData($composer);
        }

        return true;
    }

    /**
     * Run composer update for a specific package
     */
    public function runUpdate(string $packageName): array
    {
        $command = ['composer', 'update', $packageName, '--no-interaction', '--optimize-autoloader'];
        
        $process = new Process($command, base_path());
        $process->setTimeout(300);
        $process->run();

        return [
            'success' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    protected function getComposerData(): array
    {
        if (!File::exists($this->composerPath)) {
            return [];
        }

        return json_decode(File::get($this->composerPath), true) ?? [];
    }

    protected function saveComposerData(array $data): bool
    {
        try {
            File::put(
                $this->composerPath,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
            );
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to save composer.json', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
