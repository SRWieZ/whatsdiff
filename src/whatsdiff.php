<?php


use Composer\Semver\Comparator;

if (! class_exists('\Composer\InstalledVersions')) {
    require __DIR__.'/../vendor/autoload.php';
}

if (class_exists('\NunoMaduro\Collision\Provider')) {
    (new \NunoMaduro\Collision\Provider())->register();
} else {
    error_reporting(0);
    // TODO : register a function to catch exception and show nice exception
}

// Argument parsing
$argv = array_slice($argv, 1); // Remove script name
$options['show_version'] = false;
$options['ignore_last'] = false;

foreach ($argv as $key => $arg) {
    if (in_array($arg, ['-V', '--version'])) {
        $options['show_version'] = true;
        unset($argv[$key]);
    }
    if (in_array($arg, ['--ignore-last'])) {
        $options['ignore_last'] = true;
        unset($argv[$key]);
    }
}

// Re-index array after removing options
$argv = array_values($argv);
$command = $argv[0] ?? null;

// Function to display help information
function showHelp(): void
{
    $version = '@git_tag@';
    echo 'Usage: whatsdiff'.PHP_EOL;
    echo PHP_EOL;
    echo "See what's changed in your project's dependencies".PHP_EOL;
    echo PHP_EOL;
    echo 'Options:'.PHP_EOL;
    echo '  -V, --version         Show app versions'.PHP_EOL;
    echo '      --ignore-last     Ignore last uncommited changes'.PHP_EOL;
    // echo '      --back={n}        Number of times to go back in time'.PHP_EOL;
    // echo '      --json            Return a json result'.PHP_EOL;
    echo PHP_EOL;
    echo 'Commands:'.PHP_EOL;
    echo '  help                  Show this help information'.PHP_EOL;
    // echo '  between [from] [to]   Show differences between two commit hash'.PHP_EOL;
    // echo '  on [commit_hash]      Show packages updates occurred at a given commit hash'.PHP_EOL;
    echo PHP_EOL;
    echo 'Informations:'.PHP_EOL;
    echo ! str_starts_with($version, '@git_tag') ? '  Version: '.$version.PHP_EOL : '';
    echo '  PHP version: '.phpversion().PHP_EOL;
    echo '  Built with https://github.com/box-project/box'.PHP_EOL;
    echo php_sapi_name() == 'micro' ? '  Compiled with https://github.com/crazywhalecc/static-php-cli'.PHP_EOL : '';
}

function gitLogOfFile(string $filename): array
{
    $output = shell_exec("git log --pretty=format:'%h' -- '$filename'"); // TODO: escape filename

    if (is_null($output)) {
        return [];
    }

    return explode("\n", $output);
}

function isFileHasBeenRecentlyUpdated(string $filename): bool
{
    // Execute the command and get the output
    $output = shell_exec("git status --porcelain");

    if (is_null($output)) {
        return false;
    }

    $status = collect(explode("\n", trim($output)))
        ->mapWithKeys(function ($line) {
            $line = array_values(array_filter(explode(' ', $line)));

            return [$line[1] => $line[0]];
        });

    return in_array($status->get($filename), [
        // todo: read the docs
        'AM', // Added and modified
        'M', // Modified
        'A', // Added
        '??' // Untracked
    ]);
}

function getFileContentOfCommit(string $filename, string $commitHash): string
{
    return shell_exec("git show $commitHash:$filename");
}

function getCommitHashToCompare(array $commitLogs, bool $recentlyUpdated): array
{
    $last = $recentlyUpdated ? null : $commitLogs[0];

    $previousHashKey = $recentlyUpdated ? 0 : 1;

    $previous = $commitLogs[$previousHashKey] ?? null;

    return [$last, $previous];
}

function getFilesToCompare(string $filename, ?string $lastHash, ?string $previousHash): array
{
    $last = $lastHash ? getFileContentOfCommit($filename, $lastHash) : file_get_contents($filename);
    $previous = $previousHash ? getFileContentOfCommit($filename, $previousHash) : null;

    return [$last, $previous];
}

function extractComposerPackagesVersions($composerLockContent): array
{
    return collect($composerLockContent['packages'] ?? [])
        ->merge($composerLockContent['packages-dev'])
        ->mapWithKeys(fn ($package) => [$package['name'] => $package['version']])
        ->toArray();
}

function extractNpmjsPackagesVersions($composerLockContent): array
{
    return collect($composerLockContent['packages'] ?? [])
        ->mapWithKeys(fn ($package, $key) => [
            str_replace('node_modules/', '', $key) => $package['version']
        ])
        ->filter(fn ($version, $name) => ! empty($name))
        ->toArray();
}

function diffComposerLockPackages($last, $previous)
{
    $last = json_decode($last, associative: true);
    $previous = json_decode($previous ?? '{}', associative: true);

    $last = extractComposerPackagesVersions($last);
    $previous = extractComposerPackagesVersions($previous);

    $diff = collect($previous)
        ->mapWithKeys(fn ($version, $name) => [
            $name => [
                'from' => $version,
                'to'   => $last[$name] ?? null,
            ]
        ]);

    $newPackages = collect($last)
        ->diffKeys($previous)
        ->mapWithKeys(fn ($version, $name) => [
            $name => [
                'from' => null,
                'to'   => $version,
            ]
        ]);


    return $diff->merge($newPackages)
        ->filter(fn ($el) => $el['from'] !== $el['to'])
        ->sortKeys()
        ->toArray();
}

function diffPackageLockPackages($last, $previous)
{
    $last = json_decode($last, associative: true);
    $previous = json_decode($previous ?? '{}', associative: true);

    $last = extractNpmjsPackagesVersions($last);
    $previous = extractNpmjsPackagesVersions($previous);

    $diff = collect($previous)
        ->mapWithKeys(fn ($version, $name) => [
            $name => [
                'from' => $version,
                'to'   => $last[$name] ?? null,
            ]
        ]);

    $newPackages = collect($last)
        ->diffKeys($previous)
        ->mapWithKeys(fn ($version, $name) => [
            $name => [
                'from' => null,
                'to'   => $version,
            ]
        ]);


    return $diff->merge($newPackages)
        ->filter(fn ($el) => $el['from'] !== $el['to'])
        ->sortKeys()
        ->toArray();
}

function printDiff(array $diff, $type = 'composer'): void
{
    if (! count($diff)) {
        echo ' → No changes detected'.PHP_EOL;

        return;
    }

    $maxStrLen = max(array_map('strlen', array_keys($diff)));
    $maxStrLenVersion = max(array_map(
        fn ($el) => strlen($el['from']),
        array_filter($diff, fn ($el) => $el['from'] !== null)
    ) ?: [0]);
    foreach ($diff as $package => $infos) {
        if ($infos['from'] !== null && $infos['to'] !== null) {
            if (Comparator::greaterThan($infos['to'], $infos['from'])) {
                $releases = match ($type) {
                    'composer' => getComposerReleases($package, $infos['from'], $infos['to']),
                    'npmjs' => getNpmjsReleases($package, $infos['from'], $infos['to']),
                    default => [],
                };

                $nbReleases = count($releases);

                echo "\033[36m↑\033[0m ".str_pad($package, $maxStrLen).' : '.str_pad(
                    $infos['from'],
                    $maxStrLenVersion
                ).' => '.$infos['to'].($nbReleases > 1 ? " ($nbReleases releases)" : "").PHP_EOL;
            } else {
                echo "\033[33m↓\033[0m ".str_pad($package, $maxStrLen).' : '.str_pad(
                    $infos['from'],
                    $maxStrLenVersion
                ).' => '.$infos['to'].PHP_EOL;
            }
        } elseif ($infos['from'] === null) {
            echo "\033[32m+\033[0m ".str_pad($package, $maxStrLen).' : '.$infos['to'].PHP_EOL;
        } elseif ($infos['to'] === null) {
            echo "\033[31m×\033[0m ".str_pad($package, $maxStrLen).' : '.$infos['from'].PHP_EOL;
        }
    }
}

function getComposerReleases(string $package, string $from, string $to): array
{
    $packageInfos = file_get_contents('https://repo.packagist.org/p2/'.$package.'.json');
    $packageInfos = json_decode($packageInfos, associative: true);

    $versions = $packageInfos['packages'][$package];

    $returnVersions = [];

    $foundTo = false;
    $foundFrom = false;
    foreach ($versions as $infos) {
        if ($infos['version'] === $from) {
            $foundFrom = true;
        }

        if ($infos['version'] === $to) {
            $foundTo = true;
        }

        if (Comparator::greaterThan($infos['version'], $from) && Comparator::lessThan($infos['version'], $to)) {
            $returnVersions[] = $infos['version'];
        }

        if ($foundFrom && $foundTo) {
            break;
        }
    }

    return $returnVersions;
}


function getNpmjsReleases(string $package, string $from, string $to): array
{
    $packageInfos = file_get_contents('https://registry.npmjs.org/'.urlencode($package));
    $packageInfos = json_decode($packageInfos, associative: true);

    $versions = $packageInfos['versions'];

    $returnVersions = [];

    $foundTo = false;
    $foundFrom = false;
    foreach ($versions as $infos) {
        if ($infos['version'] === $from) {
            $foundFrom = true;
        }

        if ($infos['version'] === $to) {
            $foundTo = true;
        }

        if (Comparator::greaterThan($infos['version'], $from) && Comparator::lessThan($infos['version'], $to)) {
            $returnVersions[] = $infos['version'];
        }

        if ($foundFrom && $foundTo) {
            break;
        }
    }

    return $returnVersions;
}

if ($command === 'help' || $options['show_version']) {
    showHelp();
    exit;
}


$filename = 'composer.lock';

$commitLogs = gitLogOfFile($filename);

$recentlyUpdated = ! $options['ignore_last'] && isFileHasBeenRecentlyUpdated($filename);

if (! $recentlyUpdated && empty($commitLogs)) {
    echo 'No commit logs found for '.$filename.PHP_EOL;
} else {

    if ($recentlyUpdated) {
        echo 'Uncommited changes detected on '.$filename.PHP_EOL;
    }

    [$lastHash, $previousHash] = getCommitHashToCompare($commitLogs, $recentlyUpdated);

    if ($previousHash === null) {
        echo "No previous commit found, $filename has just been created".PHP_EOL;
    } else {
        echo $filename.' changed between '.$previousHash.' and '.($lastHash ?? 'uncommited changes').PHP_EOL;
    }

    echo PHP_EOL;

    [$last, $previous] = getFilesToCompare($filename, $lastHash, $previousHash);

    printDiff(diffComposerLockPackages($last, $previous));
}


echo PHP_EOL.'----------'.PHP_EOL.PHP_EOL;


$filename = 'package-lock.json';

$commitLogs = gitLogOfFile($filename);

$recentlyUpdated = ! $options['ignore_last'] && isFileHasBeenRecentlyUpdated($filename);

if (! $recentlyUpdated && empty($commitLogs)) {
    echo 'No commit logs found for '.$filename.PHP_EOL;
} else {
    if ($recentlyUpdated) {
        echo 'Uncommited changes detected on '.$filename.PHP_EOL;
    }

    [$lastHash, $previousHash] = getCommitHashToCompare($commitLogs, $recentlyUpdated);

    if ($previousHash === null) {
        echo "No previous commit found, $filename has just been created".PHP_EOL;
    } else {
        echo $filename.' changed between '.$previousHash.' and '.($lastHash ?? 'uncommited changes').PHP_EOL;
    }

    [$last, $previous] = getFilesToCompare($filename, $lastHash, $previousHash);

    echo PHP_EOL;
    printDiff(diffPackageLockPackages($last, $previous), type: 'npmjs');
}

// getComposerReleases('laravel/framework', 'v11.19.0', 'v11.22.0');
// getComposerReleases('srwiez/svgtinyps-cli', 'v1.0', 'v1.3');
// dump(getNpmjsReleases('alpinejs', '3.10.5', '3.14.1'));
// dump(getNpmjsReleases('electron-to-chromium', '1.5.13', '1.5.25'));

exit(0);
