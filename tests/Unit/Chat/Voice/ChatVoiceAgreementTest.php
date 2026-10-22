<?php

namespace Tests\Unit\Chat\Voice;

use App\Services\Chat\ChatVoiceInspector;
use App\Services\Chat\Voice\ChatVoiceChecker;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\Support\ChatVoice\Mp4Fixtures;
use Tests\Support\ChatVoice\OggFixtures;
use Tests\Support\ChatVoice\WebmFixtures;

/**
 * Spec section 3: on every good fixture the checker gives the same result as ffprobe, within 20 ms.
 * Runs only where ffprobe is installed: set CHAT_VOICE_PROBE_BINARY to its path.
 */
final class ChatVoiceAgreementTest extends TestCase
{
    private array $warnings = [];

    public static function goodFiles(): array
    {
        $cases = [];
        foreach (ChatVoiceCheckerTest::recordings() as $name => [$file]) {
            $cases[$name] = [fn () => file_get_contents(ChatVoiceCheckerTest::FIXTURES.'/'.$file)];
        }
        foreach ([5.0, 119.0] as $seconds) {
            foreach (['chrome', 'firefox', 'known'] as $style) {
                $cases["generated WebM $style $seconds s"] = [fn () => WebmFixtures::file(['style' => $style, 'seconds' => $seconds])->bytes];
            }
            // Generated Vorbis is left out: its setup header is opaque bytes, which ffprobe rightly cannot read.
            $cases["generated Ogg Opus $seconds s"] = [fn () => OggFixtures::file(['seconds' => $seconds])->bytes];
            $cases["generated MP4 $seconds s"] = [fn () => Mp4Fixtures::file(['seconds' => $seconds])->bytes];
            $cases["generated MP4 mdat first $seconds s"] = [fn () => Mp4Fixtures::file(['seconds' => $seconds, 'movieFirst' => false])->bytes];
            $cases["generated MP4 fragmented $seconds s"] = [fn () => Mp4Fixtures::file(['seconds' => $seconds, 'fragmented' => true])->bytes];
        }

        return $cases;
    }

    #[DataProvider('goodFiles')]
    public function test_agrees_with_ffprobe_within_twenty_milliseconds(\Closure $make): void
    {
        $inspector = $this->inspector();
        $bytes = $make();
        $path = tempnam(sys_get_temp_dir(), 'oblivion-voice-');
        try {
            file_put_contents($path, $bytes);
            $own = (new ChatVoiceChecker)->check($bytes);
            $second = $inspector->secondOpinion($path);
            self::assertSame(['ready', 'ready'], [$own->state, $second->state]);
            self::assertSame($second->mime, $own->mime);
            self::assertLessThanOrEqual(ChatVoiceInspector::AGREEMENT_MS, abs($own->durationMs - $second->durationMs), "checker {$own->durationMs} ms, ffprobe {$second->durationMs} ms");
            // With ffprobe configured both run, they agree, and the longer length is kept.
            $both = $inspector->inspect($path);
            self::assertSame(['ready', $own->mime, max($own->durationMs, $second->durationMs)], [$both->state, $both->mime, $both->durationMs]);
            self::assertSame([], $this->warnings);
        } finally {
            @unlink($path);
        }
    }

    public function test_both_refuse_opus_inside_mp4(): void
    {
        $inspector = $this->inspector();
        $path = ChatVoiceCheckerTest::FIXTURES.'/chrome-mp4-opus-5s.m4a';
        self::assertSame('blocked', $inspector->secondOpinion($path)->state);
        self::assertSame('blocked', $inspector->inspect($path)->state);
        self::assertSame([], $this->warnings);
    }

    private function inspector(): ChatVoiceInspector
    {
        $binary = (string) getenv('CHAT_VOICE_PROBE_BINARY');
        if ($binary === '' || ! is_file($binary) || ! is_executable($binary)) {
            self::markTestSkipped('Set CHAT_VOICE_PROBE_BINARY to an ffprobe program to compare the checker with it.');
        }
        $warnings = &$this->warnings;
        $logger = new class($warnings) extends AbstractLogger
        {
            public function __construct(private array &$warnings) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->warnings[] = [$level, (string) $message, $context];
            }
        };

        return new ChatVoiceInspector(new Repository(['chat' => ['files' => ['voice_probe_binary' => $binary]]]), null, $logger);
    }
}
