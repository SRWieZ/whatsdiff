<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process as SymfonyProcess;

function initTempDirectory(bool $initGit = true): string
{
    $tempDir = sys_get_temp_dir().'/whatsdiff-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    if ($initGit) {
        $process = new SymfonyProcess(['git', 'init'], $tempDir);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Failed to initialize git repository: {$process->getErrorOutput()}");
        }

        $process = new SymfonyProcess(['git', 'config', 'user.email', 'test@example.com'], $tempDir);
        $process->run();

        $process = new SymfonyProcess(['git', 'config', 'user.name', 'Test User'], $tempDir);
        $process->run();
    }

    return $tempDir;
}


function cleanupTempDirectory(string $tempDir): void
{
    if (! is_dir($tempDir)) {
        return;
    }

    $tempDir = str_replace('/', DIRECTORY_SEPARATOR, $tempDir);

    if (PHP_OS_FAMILY === 'Windows') {
        // Use PowerShell Remove-Item with Force - most effective for locked files
        $process = new SymfonyProcess([
            'powershell', '-Command',
            "Remove-Item -Path '$tempDir' -Recurse -Force -ErrorAction SilentlyContinue"
        ]);
        $process->setTimeout(30);
        $process->run();

        // If PowerShell fails, try standard rmdir as backup
        if (!$process->isSuccessful() && is_dir($tempDir)) {
            $process = new SymfonyProcess(['cmd', '/c', 'rmdir', '/s', '/q', $tempDir]);
            $process->run();
        }

        // Final check and warning
        if (is_dir($tempDir)) {
            echo "Warning: Could not clean up temp directory: ".$tempDir."\n";
        }
    } else {
        $process = new SymfonyProcess(['rm', '-rf', $tempDir]);
        $process->run();

        if (! $process->isSuccessful()) {
            echo "Warning: Could not clean up temp directory: ".$tempDir."\n";
        }
    }
}


function runCommand(string $command, ?string $cwd = null): string
{
    $workingDir = $cwd ?? test()->tempDir ?? getcwd();

    // On Windows, we need to handle command escaping differently
    if (PHP_OS_FAMILY === 'Windows') {
        // For Windows, replace single quotes with double quotes for commit messages
        $command = preg_replace("/git commit -m '([^']+)'/", 'git commit -m "$1"', $command);
    }

    // Parse command into array for Process constructor
    $commandParts = [];

    // Handle quoted strings and regular arguments
    preg_match_all('/\'[^\']*\'|"[^"]*"|\S+/', $command, $matches);
    foreach ($matches[0] as $part) {
        // Remove surrounding quotes if present
        if ((str_starts_with($part, '"') && str_ends_with($part, '"')) ||
            (str_starts_with($part, "'") && str_ends_with($part, "'"))) {
            $commandParts[] = substr($part, 1, -1);
        } else {
            $commandParts[] = $part;
        }
    }

    $process = new SymfonyProcess($commandParts, $workingDir);
    $process->setTimeout(60); // 1 minute timeout
    $process->run();

    // Special handling for Windows rmdir cleanup - don't fail if it's just file locking
    if (! $process->isSuccessful()) {
        if (PHP_OS_FAMILY === 'Windows' && str_contains($command, 'rmdir') &&
            str_contains($process->getErrorOutput(), 'being used by another process')) {
            // Just warn, don't fail - this is a common Windows issue
            echo "Warning: Could not clean up temp directory (file in use): ".$command."\n";

            return $process->getOutput();
        }
        throw new \RuntimeException("Command failed: {$command}\nOutput: {$process->getOutput()}\nError: {$process->getErrorOutput()}");
    }

    return $process->getOutput();
}


function runWhatsDiff(array $args = [], ?string $cwd = null): SymfonyProcess
{
    $workingDir = $cwd ?? test()->tempDir ?? getcwd();
    $binPath = realpath(__DIR__.'/../bin/whatsdiff');

    // Find PHP executable
    $executableFinder = new ExecutableFinder();
    $phpBinary = $executableFinder->find('php');

    if (!$phpBinary) {
        throw new \RuntimeException('PHP executable not found');
    }

    // Build command array
    $command = array_merge([$phpBinary, $binPath], $args);

    // Create process
    $process = new SymfonyProcess($command, $workingDir);
    $process->setTimeout(120); // 2 minutes timeout

    // Run the process
    $process->run();

    return $process;
}

/**
 * Generate a composer.lock file content from a simple package array
 *
 * @param array<string, string> $packages Array of packages with format ['package/name' => 'version']
 * @return string JSON content for composer.lock
 */
function generateComposerLock(array $packages): string
{
    $lockPackages = [];

    foreach ($packages as $name => $version) {
        $package = [
            'name' => $name,
            'version' => $version,
            'source' => [
                'type' => 'git',
                'url' => "https://github.com/{$name}.git",
            ],
        ];

        // Add dist URL for private packages (livewire/flux-pro uses private registry)
        if (str_starts_with($name, 'livewire/flux-pro')) {
            $package['dist'] = [
                'type' => 'zip',
                'url' => "https://composer.fluxui.dev/dists/{$name}/{$version}.zip",
            ];
        }

        $lockPackages[] = $package;
    }

    $composerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => bin2hex(random_bytes(16)),
        'packages' => $lockPackages,
        'packages-dev' => [],
        'aliases' => [],
        'minimum-stability' => 'stable',
        'stability-flags' => [],
        'prefer-stable' => true,
        'prefer-lowest' => false,
        'platform' => [],
        'platform-dev' => [],
        'plugin-api-version' => '2.0.0',
    ];

    return json_encode($composerLock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Generate a package-lock.json file content from a simple package array
 *
 * @param array<string, string> $packages Array of packages with format ['package-name' => 'version']
 * @return string JSON content for package-lock.json
 */
function generatePackageLock(array $packages): string
{
    $lockPackages = [
        '' => [
            'name' => 'test-project',
            'version' => '1.0.0',
            'license' => 'ISC',
            'dependencies' => [],
        ],
    ];

    $dependencies = [];

    foreach ($packages as $name => $version) {
        $lockPackages["node_modules/{$name}"] = [
            'version' => $version,
            'resolved' => "https://registry.npmjs.org/{$name}/-/{$name}-{$version}.tgz",
            'integrity' => 'sha512-' . base64_encode(random_bytes(32)),
            'license' => 'MIT',
        ];

        $dependencies[$name] = "^{$version}";
    }

    $lockPackages['']['dependencies'] = $dependencies;

    $packageLock = [
        'name' => 'test-project',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'requires' => true,
        'packages' => $lockPackages,
    ];

    return json_encode($packageLock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
