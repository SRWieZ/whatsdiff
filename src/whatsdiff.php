<?php

error_reporting(0);


if ( ! class_exists('\Composer\InstalledVersions')) {
    require __DIR__.'/../vendor/autoload.php';
}

// Argument parsing
$argv = array_slice($argv, 1); // Remove script name
$options['show_version'] = false;

foreach ($argv as $key => $arg) {
    if (in_array($arg, ['-V', '--version'])) {
        $options['show_version'] = true;
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
    echo 'Informations:'.PHP_EOL;
    echo ! str_starts_with($version, '@git_tag') ? '  Version: '.$version.PHP_EOL : '';
    echo '  PHP version: '.phpversion().PHP_EOL;
    echo '  Built with https://github.com/box-project/box'.PHP_EOL;
    echo php_sapi_name() == 'micro' ? '  Compiled with https://github.com/crazywhalecc/static-php-cli'.PHP_EOL : '';
}

if ($command === 'help' || $options['show_version']) {
    showHelp();
    exit;
}

