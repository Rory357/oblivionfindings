<?php
// Probe: a real AAC .m4a whose stts deltas and header durations are divided by 8. Do the checker and
// ffprobe both trust the container timing? usage: php mp4-understated.php <worktree> <in> <out> <ffprobe>
[$_, $worktree, $in, $out, $ffprobe] = $argv;
require $worktree.'/vendor/autoload.php';
use App\Services\Chat\ChatVoiceInspector;
use App\Services\Chat\Voice\ChatVoiceChecker;
use Illuminate\Config\Repository;
$d = file_get_contents($in);
$containers = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts'];
$walk = function (int $start, int $end) use (&$walk, &$d, $containers) {
    for ($p = $start; $p < $end;) {
        $size = unpack('N', $d, $p)[1]; $type = substr($d, $p + 4, 4);
        if (in_array($type, $containers, true)) { $walk($p + 8, $p + $size); }
        $v = ord($d[$p + 8]);
        $scale32 = function (int $at) use (&$d) { $d = substr_replace($d, pack('N', intdiv(unpack('N', $d, $at)[1], 8)), $at, 4); };
        if ($type === 'mvhd' || $type === 'mdhd') { $v === 0 ? $scale32($p + 8 + 4 + 12) : throw new RuntimeException('v1'); }
        if ($type === 'tkhd') { $v === 0 ? $scale32($p + 8 + 4 + 16) : throw new RuntimeException('v1'); }
        if ($type === 'elst') { $n = unpack('N', $d, $p + 12)[1]; for ($i = 0; $i < $n; $i++) { $scale32($p + 16 + 12 * $i); } }
        if ($type === 'stts') { $n = unpack('N', $d, $p + 12)[1]; for ($i = 0; $i < $n; $i++) { $scale32($p + 16 + 8 * $i + 4); } }
        $p += $size;
    }
};
$walk(0, strlen($d));
file_put_contents($out, $d);
$checker = new ChatVoiceChecker;
$inspector = new ChatVoiceInspector(new Repository(['chat' => ['files' => ['voice_probe_binary' => $ffprobe]]]));
foreach (['original' => $in, 'crafted' => $out] as $label => $path) {
    $own = $checker->check(file_get_contents($path));
    $second = $inspector->secondOpinion($path);
    printf("%-9s checker %s %s | ffprobe %s %s\n", $label, $own->state, $own->durationMs ?? $own->errorCode, $second->state, $second->durationMs ?? $second->errorCode);
}
