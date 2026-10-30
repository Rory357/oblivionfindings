<?php
// Verdicts for files, checker first; ffprobe's second opinion wrapped so an escape is reported, not fatal.
// usage: php vorbis-verdicts.php <worktree> <ffprobe> <files...>
[$_, $worktree, $ffprobe] = $argv;
require $worktree.'/vendor/autoload.php';
use App\Services\Chat\ChatVoiceInspector;
use App\Services\Chat\Voice\ChatVoiceChecker;
use Illuminate\Config\Repository;
$checker = new ChatVoiceChecker;
foreach (array_slice($argv, 3) as $path) {
    $own = $checker->check(file_get_contents($path));
    printf("%s\n  checker: %s %s %s\n", basename($path), $own->state, $own->mime ?? '-', $own->durationMs ?? $own->errorCode);
    $inspector = new ChatVoiceInspector(new Repository(['chat' => ['files' => ['voice_probe_binary' => $ffprobe]]]));
    try {
        $both = $inspector->inspect($path);
        printf("  inspect() with ffprobe: %s %s\n", $both->state, $both->durationMs ?? $both->errorCode);
    } catch (Throwable $e) {
        printf("  inspect() with ffprobe THREW %s: %s\n", $e::class, $e->getMessage());
    }
}
