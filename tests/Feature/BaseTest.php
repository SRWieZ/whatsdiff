<?php

test('doesnt fail', function () {
    exec('php src/whatsdiff.php', $output, $exitCode);

    expect($exitCode)->toBe(0);
});
