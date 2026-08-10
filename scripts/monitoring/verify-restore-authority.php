#!/usr/bin/php8.4
<?php

declare(strict_types=1);

use App\Support\Monitoring\RestoreReleaseAuthorityVerifier;

$root = dirname(__DIR__, 2);
$sources = [
    $root.'/app/Support/Monitoring/StrictJsonObjectDecoder.php',
    $root.'/app/Support/Monitoring/RestoreReleaseAuthorityVerifier.php',
];

foreach ($sources as $source) {
    if (! is_file($source) || is_link($source)) {
        fwrite(STDERR, "Restore release authority verification failed.\n");

        exit(1);
    }

    require_once $source;
}

try {
    $authority = (new RestoreReleaseAuthorityVerifier)->loadInstalled();
    fwrite(STDOUT, json_encode($authority, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
} catch (Throwable) {
    fwrite(STDERR, "Restore release authority verification failed.\n");

    exit(1);
}
