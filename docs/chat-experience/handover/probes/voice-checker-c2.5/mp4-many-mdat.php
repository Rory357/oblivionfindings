<?php

// Probe: an MP4 whose N one-byte chunks all sit in the LAST of M top-level 'mdat' boxes, so the final
// "every range inside an mdat" check scans all M boxes for each of the N ranges. Also: a 'moov' padded
// with many tiny 'free' boxes, to see the memory the box lists take. Times inspect() and reports peak memory.
// usage: php mp4-many-mdat.php <worktree> <mode: mdat|free> <N chunks or free boxes> <M mdat boxes>

[$_, $worktree, $mode, $n, $m] = $argv + [4 => 0];
[$n, $m] = [(int) $n, (int) $m];
require $worktree.'/vendor/autoload.php';

use App\Services\Chat\ChatVoiceInspector;
use Illuminate\Config\Repository;

function box(string $type, string $body): string
{
    return pack('N', 8 + strlen($body)).$type.$body;
}
function full(int $version = 0, int $flags = 0): string
{
    return pack('N', ($version << 24) | $flags);
}

$ftyp = box('ftyp', 'M4A '.pack('N', 0).'M4A isom');
$mvhd = box('mvhd', full().pack('N4', 0, 0, 1000, 1000).pack('N', 0x00010000).pack('n', 0x0100).str_repeat("\0", 10).str_repeat("\0", 36).str_repeat("\0", 24).pack('N', 2));
$tkhd = box('tkhd', full(0, 7).pack('N5', 0, 0, 1, 0, 1000).str_repeat("\0", 8).pack('n4', 0, 0, 0x0100, 0).str_repeat("\0", 36).pack('N2', 0, 0));
$mdhd = box('mdhd', full().pack('N4', 0, 0, 48000, 48000).pack('n2', 0x55C4, 0));
$hdlr = box('hdlr', full().pack('N', 0).'soun'.str_repeat("\0", 12)."\0");
$smhd = box('smhd', full().pack('n2', 0, 0));
$dinf = box('dinf', box('dref', full().pack('N', 1).box('url ', full(0, 1))));
$asc = "\x11\x88"; // AAC LC, 48 kHz, mono
$esds = box('esds', full()."\x03".chr(3 + 2 + 13 + 2 + strlen($asc) + 3).pack('n', 1)."\x00"
    ."\x04".chr(13 + 2 + strlen($asc))."\x40\x15".str_repeat("\0", 11)."\x05".chr(strlen($asc)).$asc."\x06\x01\x02");
$mp4a = box('mp4a', str_repeat("\0", 6).pack('n', 1).pack('n2', 0, 0).pack('N', 0).pack('n4', 1, 16, 0, 0).pack('N', 48000 << 16).$esds);
$stsd = box('stsd', full().pack('N', 1).$mp4a);

$chunks = $mode === 'mdat' ? $n : 1;
$stts = box('stts', full().pack('N', 1).pack('N2', $chunks, 1024));
$stsc = box('stsc', full().pack('N', 1).pack('N3', 1, 1, 1));
$stsz = box('stsz', full().pack('N2', 1, $chunks));
$free = $mode === 'free' ? str_repeat(box('free', ''), $n) : '';

// Lay out once with placeholder offsets to learn where the data byte lands, then fill them in.
$build = function (int $dataAt) use ($ftyp, $mvhd, $tkhd, $mdhd, $hdlr, $smhd, $dinf, $stsd, $stts, $stsc, $stsz, $chunks, $free, $m) {
    $stco = box('stco', full().pack('N', $chunks).str_repeat(pack('N', $dataAt), $chunks));
    $moov = box('moov', $mvhd.$free.box('trak', $tkhd.box('mdia', $mdhd.$hdlr.box('minf', $smhd.$dinf.box('stbl', $stsd.$stts.$stsc.$stsz.$stco)))));

    return $ftyp.$moov.str_repeat(box('mdat', ''), max(0, $m - 1)).box('mdat', str_repeat("\x21", 16));
};
$draft = $build(0);
$bytes = $build(strlen($draft) - 16);

$path = tempnam(sys_get_temp_dir(), 'probe-');
file_put_contents($path, $bytes);
$inspector = new ChatVoiceInspector(new Repository);
$before = memory_get_usage();
$t = hrtime(true);
$r = $inspector->inspect($path);
printf("%s N=%d M=%d (%.2f MiB): %s %s in %.3f s, peak memory %.0f MiB (limit %s)\n", $mode, $n, $m, strlen($bytes) / 1048576,
    $r->state, $r->errorCode ?? $r->durationMs, (hrtime(true) - $t) / 1e9, memory_get_peak_usage() / 1048576, ini_get('memory_limit'));
@unlink($path);
