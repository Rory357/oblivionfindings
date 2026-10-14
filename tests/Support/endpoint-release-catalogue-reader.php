<?php

// Read-only protocol fixture: exercises the real download service without
// booting Laravel, opening a database or installing/executing artifact bytes.
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 4 || ! ctype_digit($argv[3])) {
    fwrite(STDERR, "Invalid fixture arguments.\n");
    exit(2);
}

try {
    $reader = new EndpointReleaseCatalogue($argv[1], $argv[2], (int) $argv[3]);
    $catalogue = $reader->catalogue();
    $artifacts = [];
    foreach ($catalogue['artifacts'] as $artifact) {
        $download = $reader->download($artifact['id'], $artifact['sha256']);
        try {
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $download['stream']);
            if ($size !== $artifact['size'] || ! hash_equals($artifact['sha256'], hash_final($hash))) {
                throw new RuntimeException('Unexpected downloaded bytes.');
            }
            $artifacts[] = $download['artifact'];
        } finally {
            fclose($download['stream']);
        }
    }
    fwrite(STDOUT, json_encode([
        'revision' => $catalogue['revision'],
        'artifacts' => $artifacts,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable) {
    fwrite(STDERR, "Catalogue or downloaded bytes rejected by the application.\n");
    exit(1);
}
