<?php

namespace Tests\Unit\Chat\Voice;

use App\Services\Chat\ChatVoiceInspector;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\ChatVoice\Mp4Fixtures;
use Tests\Support\ChatVoice\OggFixtures;
use Tests\Support\ChatVoice\VoiceFile;
use Tests\Support\ChatVoice\WebmFixtures;
use Throwable;

/**
 * Spec section 3: flip and cut bytes of the good fixtures, 10,000 cases. inspect() must never throw,
 * never take two seconds on a file, and never return 'ready' for a file whose structure is broken.
 *
 * A file counts as broken when the change provably breaks a format rule:
 *  - any change to an Ogg file (every page has a CRC, and the stream must end with its end page);
 *  - a cut strictly inside an element, box or page whose size is declared;
 *  - a change to a critical byte: sizes, the IDs and box types the format requires, and fixed values.
 * Every other mutation must still finish cleanly, and may only pass as the format it started as.
 */
final class ChatVoiceMutationTest extends TestCase
{
    private const CASES = 10_000;

    public function test_ten_thousand_mutations_never_throw_never_hang_and_never_pass_a_broken_file(): void
    {
        $generated = [
            WebmFixtures::file(['style' => 'chrome', 'seed' => 11]),
            WebmFixtures::file(['style' => 'firefox', 'seed' => 12, 'seconds' => 2.0]),
            WebmFixtures::file(['style' => 'known', 'seed' => 13, 'seconds' => 2.0]),
            Mp4Fixtures::file(['seed' => 14, 'seconds' => 2.0]),
            Mp4Fixtures::file(['seed' => 15, 'seconds' => 2.0, 'movieFirst' => false]),
            Mp4Fixtures::file(['seed' => 16, 'seconds' => 3.0, 'fragmented' => true]),
        ];
        $oggs = [
            OggFixtures::file(['seed' => 17, 'seconds' => 2.0]),
            OggFixtures::file(['seed' => 18, 'seconds' => 2.0, 'codec' => 'vorbis']),
            ...$this->recordings(['firefox-ogg-opus-5s.ogg', 'firefox-ogg-opus-119s.ogg'], true),
        ];
        $recorded = $this->recordings(['chrome-webm-opus-5s.webm', 'edge-webm-opus-5s.webm', 'firefox-webm-opus-5s.webm',
            'firefox-webm-opus-paused.webm', 'chrome-webm-opus-119s.webm', 'chrome-mp4-aac-5s.m4a'], false);
        $everything = [...$generated, ...$oggs, ...$recorded];

        $random = new Randomizer(new Mt19937(20261023));
        $inspector = new ChatVoiceInspector(new Repository);
        $path = tempnam(sys_get_temp_dir(), 'oblivion-voice-');
        $failures = [];
        $tally = [];
        $slowest = 0.0;
        try {
            for ($case = 0; $case < self::CASES; $case++) {
                [$kind, $file] = match (true) {
                    $case < 2_000 => ['ogg', $random->pickArrayKeys($oggs, 1)[0]],
                    $case < 4_000 => ['cut', $random->pickArrayKeys([...$generated, $oggs[0], $oggs[1]], 1)[0]],
                    $case < 7_000 => ['critical', $random->pickArrayKeys($generated, 1)[0]],
                    default => ['any', $random->pickArrayKeys($everything, 1)[0]],
                };
                $file = match ($kind) {
                    'ogg' => $oggs[$file],
                    'cut' => [...$generated, $oggs[0], $oggs[1]][$file],
                    'critical' => $generated[$file],
                    default => $everything[$file],
                };
                [$bytes, $label, $broken] = $this->mutate($kind, $file, $random);
                if ($broken) {
                    $tally["$kind must block"] = ($tally["$kind must block"] ?? 0) + 1;
                }
                file_put_contents($path, $bytes);
                $started = hrtime(true);
                try {
                    $result = $inspector->inspect($path);
                } catch (Throwable $e) {
                    $failures[] = "#$case $label threw ".$e::class;

                    continue;
                }
                $seconds = (hrtime(true) - $started) / 1e9;
                $slowest = max($slowest, $seconds);
                $tally["$kind {$result->state}"] = ($tally["$kind {$result->state}"] ?? 0) + 1;
                if ($seconds >= 2.0) {
                    $failures[] = sprintf('#%d %s took %.2f s', $case, $label, $seconds);
                }
                if (! in_array($result->state, ['ready', 'blocked'], true)) {
                    $failures[] = "#$case $label returned {$result->state}";
                }
                if ($result->state === 'ready' && $broken) {
                    $failures[] = "#$case $label passed although its structure is broken";
                }
                if ($result->state === 'ready' && $result->mime !== $file->mime) {
                    $failures[] = "#$case $label passed as {$result->mime}";
                }
            }
        } finally {
            @unlink($path);
        }
        ksort($tally);
        $summary = sprintf('%d of %d cases failed; slowest %.3f s; %s', count($failures), self::CASES, $slowest, json_encode($tally));
        self::assertSame([], array_slice($failures, 0, 25), $summary);
        self::assertLessThan(2.0, $slowest, $summary);
        // Every kind of change was really tried: all Ogg changes and nearly all critical-byte changes are
        // provably broken (a size that lands exactly on another boundary is the rare undecided case).
        self::assertSame(2_000, $tally['ogg must block'] ?? 0, $summary);
        self::assertGreaterThan(2_900, $tally['critical must block'] ?? 0, $summary);
        self::assertGreaterThan(500, $tally['cut must block'] ?? 0, $summary);
    }

    /** @return array{string, string, bool} the mutated bytes, a description, and whether the structure is provably broken */
    private function mutate(string $kind, VoiceFile $file, Randomizer $random): array
    {
        $bytes = $file->bytes;
        $length = strlen($bytes);
        $operation = match ($kind) {
            'cut' => 'cut',
            'critical' => 'critical',
            default => ['flip', 'flip', 'flip', 'cut', 'insert', 'delete', 'duplicate', 'zero', 'swap'][$random->getInt(0, 8)],
        };
        $label = "{$file->name} $operation";
        switch ($operation) {
            case 'critical':
                $offsets = $file->criticalOffsets();
                $at = $offsets[$random->getInt(0, count($offsets) - 1)];
                $bytes[$at] = chr(ord($bytes[$at]) ^ $random->getInt(1, 255));

                return [$bytes, "$label @$at", ! $file->realigns($bytes, $at)];
            case 'flip':
                $positions = [];
                for ($i = $random->getInt(1, 8); $i > 0; $i--) {
                    $at = $random->getInt(0, $length - 1);
                    $positions[] = $at;
                    $bytes[$at] = chr(ord($bytes[$at]) ^ $random->getInt(1, 255));
                }
                $broken = $file->everyChangeBreaks;
                foreach ($positions as $at) {
                    $broken = $broken || ($file->changeBreaks($at) && ! $file->realigns($bytes, $at));
                }

                return [$bytes, "$label @".implode(',', $positions), $broken];
            case 'cut':
                $at = $random->getInt(1, $length - 1);

                return [substr($bytes, 0, $at), "$label @$at", $file->cutBreaks($at)];
            case 'insert':
                $at = $random->getInt(0, $length);

                return [substr_replace($bytes, $random->getBytes($random->getInt(1, 16)), $at, 0), "$label @$at", $file->everyChangeBreaks];
            case 'delete':
                $at = $random->getInt(0, $length - 1);

                return [substr_replace($bytes, '', $at, $random->getInt(1, min(64, $length - $at))), "$label @$at", $file->everyChangeBreaks];
            case 'duplicate':
                $at = $random->getInt(0, $length - 1);
                $piece = substr($bytes, $at, $random->getInt(1, 256));

                return [substr_replace($bytes, $piece, $at, 0), "$label @$at", $file->everyChangeBreaks];
            case 'zero':
                $at = $random->getInt(0, $length - 1);
                $span = $random->getInt(1, min(32, $length - $at));
                $zeroed = substr_replace($bytes, str_repeat("\0", $span), $at, $span);

                return [$zeroed, "$label @$at", $zeroed !== $bytes && $file->everyChangeBreaks];
            default: // swap two pieces
                $size = $random->getInt(1, 64);
                $a = $random->getInt(0, $length - $size);
                $b = $random->getInt(0, $length - $size);
                [$first, $second] = [substr($bytes, $a, $size), substr($bytes, $b, $size)];
                $swapped = substr_replace(substr_replace($bytes, $second, $a, $size), $first, $b, $size);

                return [$swapped, "$label @$a,$b", $swapped !== $bytes && $file->everyChangeBreaks];
        }
    }

    /** @return list<VoiceFile> */
    private function recordings(array $names, bool $everyChangeBreaks): array
    {
        $mimes = ['webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4'];

        return array_map(fn ($name) => new VoiceFile($name, file_get_contents(ChatVoiceCheckerTest::FIXTURES.'/'.$name),
            $mimes[pathinfo($name, PATHINFO_EXTENSION)], null, [], [], $everyChangeBreaks), $names);
    }
}
