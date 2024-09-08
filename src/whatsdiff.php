<?php



if (! class_exists('\Composer\InstalledVersions')) {
    require __DIR__.'/../vendor/autoload.php';
}

error_reporting(0);
(new \NunoMaduro\Collision\Provider())->register();

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
    echo '  -V, --version       Show app versions'.PHP_EOL;
    echo '      --ignore-last   Ignore last uncommited changes'.PHP_EOL;
    echo PHP_EOL;
    echo 'Commands:'.PHP_EOL;
    echo '  help                Show this help information'.PHP_EOL;
    echo PHP_EOL;
    echo 'Informations:'.PHP_EOL;
    echo ! str_starts_with($version, '@git_tag') ? '  Version: '.$version.PHP_EOL : '';
    echo '  PHP version: '.phpversion().PHP_EOL;
    echo '  Built with https://github.com/box-project/box'.PHP_EOL;
    echo php_sapi_name() == 'micro' ? '  Compiled with https://github.com/crazywhalecc/static-php-cli'.PHP_EOL : '';
}

function gitLogOfFile(string $filename): array
{
    $output = shell_exec("git log --pretty=format:'%h' $filename");

    return explode("\n", $output);
}

function isFileHasBeenRecentlyUpdated(string $filename): bool
{
    // Execute the command and get the output
    $output = shell_exec("git status --porcelain");

    $status = collect(explode("\n", trim($output)))
        ->mapWithKeys(function ($line) {
            $line = array_values(array_filter(explode(' ', $line)));

            return [$line[1] => $line[0]];
        });

    return $status->get($filename) == 'M';
}

function getFileContentOfCommit(string $filename, string $commitHash): string
{
    return shell_exec("git show $commitHash:$filename");
}

function getFilesToCompare(string $filename, array $commitLogs, bool $recentlyUpdated): array
{
    $last = $recentlyUpdated ? file_get_contents($filename) : getFileContentOfCommit($filename, $commitLogs[0]);

    $previousHashKey = $recentlyUpdated ? 0 : 1;

    if (! isset($commitLogs[$previousHashKey])) {
        throw new Exception('Could not found any previous changes in '.$filename);
    }

    $previous = getFileContentOfCommit($filename, $commitLogs[$previousHashKey]);

    return [$last, $previous];
}

function extractComposerPackagesVersions($composerLockContent): array
{
    return collect($composerLockContent['packages'])
        ->merge($composerLockContent['packages-dev'])
        ->mapWithKeys(fn ($package) => [$package['name'] => $package['version']])
        ->toArray();
}

function diffComposerLockPackages($last, $previous)
{
    $last = json_decode($last, associative: true);
    $previous = json_decode($previous, associative: true);

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
        ->diffAssoc($previous)
        ->mapWithKeys(fn ($version, $name) => [
            $name => [
                'from' => null,
                'to'   => $version,
            ]
        ]);


    return $diff->merge($newPackages)
        ->filter(fn ($el) => $el['from'] !== $el['to'])
        ->toArray();
}

if ($command === 'help' || $options['show_version']) {
    showHelp();
    exit;
}


$filename = 'composer.lock';

$commitLogs = gitLogOfFile($filename);

$recentlyUpdated = ! $options['ignore_last'] && isFileHasBeenRecentlyUpdated($filename);

[$last, $previous] = getFilesToCompare($filename, $commitLogs, $recentlyUpdated);

dump(diffComposerLockPackages($last, $previous));
