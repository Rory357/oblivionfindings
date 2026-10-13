<?php

use Oblivion\Collector\Media\PrivateFrames;

it('preserves private framed JSON bytes and consumes distinct adjacent frames', function () {
    $stream = fopen('php://memory', 'w+b');
    try {
        $first = '{"url":"rtsps://nvr.test/private?enableSrtp","title":"管理 <camera>"}';
        PrivateFrames::write($stream, $first);
        PrivateFrames::write($stream, '{"version":1}');
        rewind($stream);
        expect(PrivateFrames::read($stream))->toBe(json_decode($first, true, flags: JSON_THROW_ON_ERROR))
            ->and(PrivateFrames::read($stream))->toBe(['version' => 1]);
    } finally {
        fclose($stream);
    }
});
it('rejects oversized zero truncated and malformed private media frames', function (string $wire) {
    $stream = fopen('php://memory', 'w+b');
    try {
        fwrite($stream, $wire);
        rewind($stream);
        $rejected = false;
        try {
            PrivateFrames::read($stream);
        } catch (RuntimeException|JsonException) {
            $rejected = true;
        } expect($rejected)->toBeTrue();
    } finally {
        fclose($stream);
    }
})->with([pack('N', 0), pack('N', 65537), pack('N', 10).'{}', pack('N', 2).'[]', pack('N', 2)."\xff\xff", pack('N',1).'{']);
