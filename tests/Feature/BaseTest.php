<?php

test('doesnt fail', function () {
    exec('php src/whatsdiff.php', $output, $exitCode);

    if ($exitCode !== 0) {
        echo implode("\n", $output);
    }

    expect($exitCode)->toBe(0);
})->skip('Yeah that wont work with an interactive UI ^^');
