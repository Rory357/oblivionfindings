<?php

// Probe: a real libvorbis file whose page granule positions are scaled down to the checker's floor,
// with every page CRC recomputed. Asks: does the checker accept a file whose audio is far longer
// than its computed length? usage: php vorbis-understated.php <worktree> <in.ogg> <out.ogg> <ffprobe>

[$_, $worktree, $in, $out, $ffprobe] = $argv;
require $worktree.'/vendor/autoload.php';

use App\Services\Chat\ChatVoiceInspector;
use App\Services\Chat\Voice\ChatVoiceChecker;
use Illuminate\Config\Repository;

function oggCrc(string $page): int
{
    static $table = null;
    if ($table === null) {
        for ($i = 0; $i < 256; $i++) {
            $r = $i << 24;
            for ($k = 0; $k < 8; $k++) {
                $r = ($r & 0x80000000) ? (($r << 1) ^ 0x04C11DB7) & 0xFFFFFFFF : ($r << 1) & 0xFFFFFFFF;
            }
            $table[$i] = $r;
        }
    }
    $crc = 0;
    $n = strlen($page);
    for ($i = 0; $i < $n; $i++) {
        $crc = (($crc << 8) & 0xFFFFFFFF) ^ $table[(($crc >> 24) & 0xFF) ^ ord($page[$i])];
    }

    return $crc;
}

$data = file_get_contents($in);
$pages = [];
$p = 0;
while ($p < strlen($data)) {
    $segments = ord($data[$p + 26]);
    $lacing = array_values(unpack('C'.$segments, $data, $p + 27));
    $size = 27 + $segments + array_sum($lacing);
    $ended = 0;
    foreach ($lacing as $v) {
        if ($v < 255) {
            $ended++;
        }
    }
    $pages[] = ['at' => $p, 'size' => $size, 'granule' => unpack('P', $data, $p + 6)[1], 'packetsEnded' => $ended];
    $p += $size;
}
// Identification header: blocksize byte at packet offset 28 (page 0 holds it alone).
$id = substr($data, $pages[0]['at'] + 27 + ord($data[$pages[0]['at'] + 26]), 30);
$rate = unpack('V', $id, 12)[1];
$short = 1 << (ord($id[28]) & 0x0F);
$long = 1 << (ord($id[28]) >> 4);
// Audio packets: all completed packets after the three headers.
$all = array_sum(array_column($pages, 'packetsEnded'));
$audioPackets = $all - 3;
$last = end($pages)['granule'];
$least = max(0, $audioPackets - 2) * intdiv($short, 2);
printf("rate %d, blocks %d/%d, audio packets %d, real last granule %d (%.1f s), checker floor %d (%.1f s)\n",
    $rate, $short, $long, $audioPackets, $last, $last / $rate, $least, $least / $rate);

$crafted = $data;
foreach ($pages as $page) {
    if ($page['granule'] <= 0) {
        continue; // header pages and pages with no packet end keep their position
    }
    $scaled = intdiv($page['granule'] * $least, $last);
    $bytes = substr($crafted, $page['at'], $page['size']);
    $bytes = substr_replace($bytes, pack('P', $scaled), 6, 8);
    $bytes = substr_replace($bytes, "\0\0\0\0", 22, 4);
    $bytes = substr_replace($bytes, pack('V', oggCrc($bytes)), 22, 4);
    $crafted = substr_replace($crafted, $bytes, $page['at'], $page['size']);
}
file_put_contents($out, $crafted);

$checker = new ChatVoiceChecker;
$inspector = new ChatVoiceInspector(new Repository(['chat' => ['files' => ['voice_probe_binary' => $ffprobe]]]));
foreach (['original' => $in, 'crafted' => $out] as $label => $path) {
    $own = $checker->check(file_get_contents($path));
    $second = $inspector->secondOpinion($path);
    $both = $inspector->inspect($path);
    printf("%-9s checker %s %s %s ms | ffprobe %s %s ms | both %s %s\n", $label, $own->state, $own->mime ?? '-', $own->durationMs ?? $own->errorCode,
        $second->state, $second->durationMs ?? $second->errorCode, $both->state, $both->durationMs ?? $both->errorCode);
}
