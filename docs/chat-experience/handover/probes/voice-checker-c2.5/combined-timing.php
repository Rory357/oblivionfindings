<?php
// Probe: N tiny valid blocks (each costs parts and time) followed by one bad all-0xFF padding packet
// filling the rest of 20 MiB. Times inspect() end to end. usage: php combined-timing.php <worktree> <blocks...>
[$_, $worktree] = $argv;
require $worktree.'/vendor/autoload.php';
use App\Services\Chat\ChatVoiceInspector;
use Illuminate\Config\Repository;
function vint(int $n): string { for ($len = 1; $len <= 8; $len++) { if ($n < (1 << (7 * $len)) - 1) { $b = ''; for ($i = $len - 1; $i >= 0; $i--) { $b .= chr(($n >> (8 * $i)) & 0xFF); } $b[0] = chr(ord($b[0]) | (0x80 >> ($len - 1))); return $b; } } }
function el(int $id, string $body): string { return ltrim(pack('N', $id), "\0").vint(strlen($body)).$body; }
function u(int $id, int $v): string { return el($id, ltrim(pack('J', $v), "\0") ?: "\0"); }
$head = el(0x1A45DFA3, u(0x4286, 1).u(0x42F7, 1).u(0x42F2, 4).u(0x42F3, 8).el(0x4282, 'webm').u(0x4287, 4).u(0x4285, 2))
    ."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".el(0x1549A966, u(0x2AD7B1, 1_000_000))
    .el(0x1654AE6B, el(0xAE, u(0xD7, 1).u(0x83, 2).el(0x86, 'A_OPUS').el(0x63A2, 'OpusHead'."\x01\x01".pack('v', 312).pack('V', 48000)."\0\0\0")))
    ."\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".u(0xE7, 0);
$tiny = "\xA3\x85\x81\x00\x00\x80\xF8"; // SimpleBlock: track 1, time 0, keyframe, one TOC-only Opus packet
$inspector = new ChatVoiceInspector(new Repository);
$path = tempnam(sys_get_temp_dir(), 'probe-');
foreach (array_slice($argv, 2) as $n) {
    $prefix = $head.str_repeat($tiny, (int) $n);
    $rest = ChatVoiceInspector::MAX_BYTES - strlen($prefix) - 8;
    $bad = "\xA3".vint($rest - 1 - 4).chr((31 << 3) | 3)."\x41".str_repeat("\xFF", $rest - 1 - 4 - 4 - 2);
    $bad = "\xA3".vint(4 + strlen(chr((31 << 3) | 3)."\x41") + ($rest - 16)).("\x81\x00\x00\x80".chr((31 << 3) | 3)."\x41".str_repeat("\xFF", $rest - 16));
    file_put_contents($path, $prefix.$bad);
    $t = hrtime(true);
    $r = $inspector->inspect($path);
    printf("%7d tiny blocks + %.1f MiB bad padding packet (%.2f MiB file): %s %s in %.3f s\n", $n, ($rest - 16) / 1048576, filesize($path) / 1048576, $r->state, $r->errorCode, (hrtime(true) - $t) / 1e9);
}
@unlink($path);
