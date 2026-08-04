<?php

use Oblivion\Collector\Exceptions\SpoolFull;
use Oblivion\Collector\Spool\CheckpointFile;
use Oblivion\Collector\Spool\EncryptedSpool;

it('encrypts length-prefixed frames at rest and restores ordered items after restart', function () {
    $directory = collectorTempDirectory('encrypted-spool');
    try {
        $checkpoint = new CheckpointFile($directory.'/checkpoint.json');
        $spool = new EncryptedSpool($directory, $checkpoint, maxBytes: 65536, maxItems: 10, maxAgeSeconds: 3600);
        expect($spool->append('item-1', 7, ['message' => 'sensitive-observation'], collectorNow()))->toBeTrue()
            ->and($spool->append('item-2', 8, ['message' => 'second'], collectorNow()))->toBeTrue();

        $disk = file_get_contents($directory.'/spool.bin');
        expect($disk)->not->toContain('sensitive-observation', 'item-1');

        $restarted = new EncryptedSpool($directory, new CheckpointFile($directory.'/checkpoint.json'), 65536, 10, 3600);
        $batch = $restarted->readBatch(10, collectorNow());
        expect(array_column($batch, 'id'))->toBe(['item-1', 'item-2'])
            ->and(array_column($batch, 'source_sequence'))->toBe([7, 8]);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('deduplicates item IDs and removes frames only after an acknowledged checkpoint', function () {
    $directory = collectorTempDirectory('spool-ack');
    try {
        $checkpoint = new CheckpointFile($directory.'/checkpoint.json');
        $spool = new EncryptedSpool($directory, $checkpoint, 65536, 10, 3600);
        expect($spool->append('same', 3, ['value' => 1], collectorNow()))->toBeTrue()
            ->and($spool->append('same', 3, ['value' => 1], collectorNow()))->toBeFalse()
            ->and($spool->count(collectorNow()))->toBe(1)
            ->and($spool->nextSourceSequence())->toBe(4);

        $spool->acknowledge(['same'], 3);

        expect($spool->count(collectorNow()))->toBe(0)
            ->and($checkpoint->read()['acknowledged_source_sequence'])->toBe(3)
            ->and($spool->nextSourceSequence())->toBe(4);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('stops new scheduled work at the cap without discarding old frames', function () {
    $directory = collectorTempDirectory('spool-cap');
    try {
        $spool = new EncryptedSpool($directory, new CheckpointFile($directory.'/checkpoint.json'), 65536, 2, 3600);
        $spool->append('item-1', 1, ['value' => 1], collectorNow());
        $spool->append('item-2', 2, ['value' => 2], collectorNow());

        expect(fn () => $spool->append('item-3', 3, ['value' => 3], collectorNow()))
            ->toThrow(SpoolFull::class, 'buffer_full')
            ->and($spool->count(collectorNow()))->toBe(2)
            ->and(array_column($spool->readBatch(10, collectorNow()), 'id'))->toBe(['item-1', 'item-2']);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('quarantines a corrupted encrypted frame instead of treating it as acknowledged', function () {
    $directory = collectorTempDirectory('spool-corrupt');
    try {
        $spool = new EncryptedSpool($directory, new CheckpointFile($directory.'/checkpoint.json'), 65536, 10, 3600);
        $spool->append('item-1', 1, ['value' => 1], collectorNow());
        $path = $directory.'/spool.bin';
        $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0xFF);
        file_put_contents($path, $bytes, LOCK_EX);

        expect($spool->readBatch(10, collectorNow()))->toBe([])
            ->and(glob($directory.'/quarantine/*.frame'))->toHaveCount(1)
            ->and($spool->status(collectorNow())['corrupted_frames'])->toBe(1);
    } finally {
        removeCollectorDirectory($directory);
    }
});
