<?php

// if Pest 3.0 is installed, use the new `arch` function
if (version_compare(Composer\InstalledVersions::getVersion('pestphp/pest'), '3.0.0', '>=')) {
    arch('php')->preset()->php();
}
