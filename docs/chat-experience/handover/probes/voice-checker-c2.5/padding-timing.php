<?php

// Probe: the Opus padding loop has no clock check. Times check() on files whose single Opus packet
// is almost the whole file and is all padding-length bytes (0xFF), in WebM and in Ogg.
// usage: php padding-timing.php <worktree> <MiB>

[$_, $worktree, $mib] = $argv;
require $worktree.'/vendor/autoload.php';

use App\Services\Chat\Voice\ChatVoiceChecker;

function vint(int $n): string
{
    for ($len = 1; $len <= 8; $len++) {
        if ($n < (1 << (7 * $len)) - 1) {
            $bytes = '';
            for ($i = $len - 1; $i >= 0; $i--) {
                $bytes .= chr(($n >> (8 * $i)) & 0xFF);
            }
            $bytes[0] = chr(ord($bytes[0]) | (0x80 >> ($len - 1)));

            return $bytes;
        }
    }
    throw new RuntimeException('too big');
}
function el(int $id, string $body): string
{
    $idBytes = ltrim(pack('N', $id), "\0");

    return $idBytes.vint(strlen($body)).$body;
}
function uintEl(int $id, int $v): string
{
    return el($id, ltrim(pack('J', $v), "\0") ?: "\0");
}

$target = (int) ($mib * 1024 * 1024);
$opusHead = 'OpusHead'."\x01\x01".pack('v', 312).pack('V', 48000)."\0\0\0";
$header = el(0x1A45DFA3, uintEl(0x4286, 1).uintEl(0x42F7, 1).uintEl(0x42F2, 4).uintEl(0x42F3, 8).el(0x4282, 'webm').uintEl(0x4287, 4).uintEl(0x4285, 2));
$info = el(0x1549A966, uintEl(0x2AD7B1, 1_000_000));
$tracks = el(0x1654AE6B, el(0xAE, uintEl(0xD7, 1).uintEl(0x83, 2).el(0x86, 'A_OPUS').el(0x63A2, $opusHead)));
$segmentStart = "\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"; // unknown size
$clusterStart = "\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".uintEl(0xE7, 0);
$fixed = strlen($header) + strlen($segmentStart) + strlen($info) + strlen($tracks) + strlen($clusterStart);
$packetLength = $target - $fixed - 20;
// TOC: config 31 (CELT FB 20 ms), code 3; count byte: padding flag + VBR off + 1 frame; then all 0xFF.
$packet = chr((31 << 3) | 3)."\x41".str_repeat("\xFF", $packetLength - 2);
$block = "\x81\x00\x00\x80".$packet;
$webm = $header.$segmentStart.$info.$tracks.$clusterStart.el(0xA3, $block);

$checker = new ChatVoiceChecker;
foreach (['WebM' => $webm] as $label => $bytes) {
    $t = hrtime(true);
    $r = $checker->check($bytes);
    printf("%s %.1f MiB, one %d-byte padding packet: %s %s in %.3f s\n", $label, strlen($bytes) / 1048576, strlen($packet), $r->state, $r->errorCode ?? '', (hrtime(true) - $t) / 1e9);
    try {
        $checker->facts($bytes);
    } catch (Throwable $e) {
        echo '  rule: '.$e->getMessage()."\n";
    }
}
