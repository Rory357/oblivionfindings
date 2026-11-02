<?php

namespace Tests\Unit\Chat;

use App\Services\Chat\ChatVoiceInspection;
use App\Services\Chat\ChatVoiceInspector;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Process\Process;
use Tests\Support\ChatVoice\OpusFrames;
use Tests\Support\ChatVoice\WebmFixtures;

final class ChatVoiceInspectorTest extends TestCase
{
    private array $paths = [];

    private function audio(string $format = 'matroska,webm', string $codec = 'opus'): array
    {
        return ['format' => ['format_name' => $format], 'streams' => [['index' => 0, 'codec_type' => 'audio', 'codec_name' => $codec]],
            'packets' => [['stream_index' => 0, 'pts_time' => '-0.007', 'duration_time' => '0.020'], ['stream_index' => 0, 'pts_time' => '0.013', 'duration_time' => '0.040']]];
    }

    public static function formats(): array
    {
        return [['matroska,webm', 'opus', 'audio/webm'], ['ogg', 'opus', 'audio/ogg'], ['mov,mp4,m4a,3gp,3g2,mj2', 'aac', 'audio/mp4']];
    }

    #[DataProvider('formats')]
    public function test_derives_browser_audio_duration_without_a_container_duration(string $format, string $codec, string $mime): void
    {
        $result = (new ChatVoiceInspector(new Repository))->analyse($this->audio($format, $codec));
        self::assertSame('ready', $result->state);
        self::assertSame($mime, $result->mime);
        self::assertSame(60, $result->durationMs);
    }

    public static function invalidCases(): array
    {
        return array_map(fn ($value) => [$value], ['video', 'extra stream', 'unknown codec', 'Ogg Vorbis', 'unknown format', 'missing duration', 'empty packets', 'foreign packet', 'invalid packet', 'negative duration', 'infinite duration', 'invalid streams']);
    }

    #[DataProvider('invalidCases')]
    public function test_rejects_non_audio_or_unverifiable_media(string $case): void
    {
        $data = $this->audio();
        match ($case) {
            'video' => $data['streams'][0]['codec_type'] = 'video',
            'extra stream' => $data['streams'][] = ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'vp8'],
            'unknown codec' => $data['streams'][0]['codec_name'] = 'unknown',
            // Refused since 23 October 2026, as the voice checker refuses it.
            'Ogg Vorbis' => [$data['format']['format_name'], $data['streams'][0]['codec_name']] = ['ogg', 'vorbis'],
            'unknown format' => $data['format']['format_name'] = 'hls',
            'missing duration' => $data['packets'][0]['duration_time'] = 'N/A',
            'empty packets' => $data['packets'] = [],
            'foreign packet' => $data['packets'][0]['stream_index'] = 1,
            'invalid packet' => $data['packets'][0] = 'broken',
            'negative duration' => $data['packets'][0]['duration_time'] = '-1',
            'infinite duration' => $data['packets'][0]['duration_time'] = '1e999',
            'invalid streams' => $data['streams'] = ['wrong' => []],
        };
        $result = (new ChatVoiceInspector(new Repository))->analyse($data);
        self::assertSame('blocked', $result->state);
        self::assertNull($result->mime);
        self::assertNull($result->durationMs);
    }

    public function test_enforces_duration_from_declared_length_packet_span_and_total(): void
    {
        $probe = new ChatVoiceInspector(new Repository);
        $declared = $this->audio();
        $declared['format']['duration'] = '121';
        $span = $this->audio();
        $span['packets'][1]['pts_time'] = '125';
        $sum = $this->audio();
        $sum['packets'] = array_fill(0, 121, ['stream_index' => 0, 'pts_time' => '0', 'duration_time' => '1']);
        foreach ([$declared, $span, $sum] as $data) {
            self::assertSame('voice_too_long', $probe->analyse($data)->errorCode);
        }
    }

    public function test_checks_voice_notes_without_ffprobe_and_never_claims_a_verdict_for_other_bytes(): void
    {
        $inspector = new ChatVoiceInspector(new Repository);
        self::assertTrue($inspector->configured());
        self::assertFalse($inspector->secondOpinionConfigured());
        $result = $inspector->inspect(__FILE__);
        self::assertSame(['blocked', null, null, 'voice_file_invalid'], [$result->state, $result->mime, $result->durationMs, $result->errorCode]);
        $path = $this->temporary(WebmFixtures::file()->bytes);
        self::assertSame(['ready', 'audio/webm', 5042], [($ready = $inspector->inspect($path))->state, $ready->mime, $ready->durationMs]);
        // Only a file that cannot be read from disk leaves the result open for a retry.
        self::assertSame(['unavailable', 'voice_check_failed'], [($missing = $inspector->inspect($path.'.missing'))->state, $missing->errorCode]);
    }

    public function test_runs_only_local_bounded_inspection_and_uses_the_returned_packets(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects(self::once())->method('setTimeout')->with(15)->willReturnSelf();
        $output = json_encode($this->audio(), JSON_THROW_ON_ERROR);
        $process->method('run')->willReturnCallback(function ($callback) use ($output) {
            $callback(Process::OUT, $output);

            return 0;
        });
        $process->method('isSuccessful')->willReturn(true);
        $inspector = new class(new Repository(['chat' => ['files' => ['voice_probe_binary' => PHP_BINARY]]]), $process) extends ChatVoiceInspector
        {
            public array $command = [];

            public function __construct(Repository $config, private Process $process)
            {
                parent::__construct($config);
            }

            protected function makeProcess(array $command): Process
            {
                $this->command = $command;

                return $this->process;
            }
        };
        // 60 ms of audio, matching the faked ffprobe packets: a 20 ms block at 0 and a 40 ms block at 20 ms.
        $path = $this->temporary(WebmFixtures::file(['style' => 'firefox', 'blocks' => fn ($blocks, $random) => [
            ['time' => 0, 'frames' => [OpusFrames::single($random)], 'samples' => 960, 'lacing' => 0, 'group' => false, 'track' => 2],
            ['time' => 20, 'frames' => [OpusFrames::single($random, 2)], 'samples' => 1920, 'lacing' => 0, 'group' => false, 'track' => 2],
        ]])->bytes);
        $result = $inspector->inspect($path);
        self::assertSame(['ready', 'audio/webm', 60], [$result->state, $result->mime, $result->durationMs]);
        self::assertContains('-protocol_whitelist', $inspector->command);
        self::assertSame('file', $inspector->command[array_search('-protocol_whitelist', $inspector->command) + 1]);
        self::assertSame($path, $inspector->command[array_key_last($inspector->command)]);
    }

    public function test_too_much_ffprobe_output_or_a_failing_stop_never_throws_out_of_inspect(): void
    {
        $path = $this->temporary(WebmFixtures::file()->bytes);
        $messages = [];
        $logger = new class($messages) extends AbstractLogger
        {
            public function __construct(private array &$messages) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = [$level, $context];
            }
        };

        // ffprobe writes 3 MiB: the output past the limit is dropped and the second opinion refuses the file.
        $flood = $this->createMock(Process::class);
        $flood->method('setTimeout')->willReturnSelf();
        $flood->method('run')->willReturnCallback(function ($callback) {
            $callback(Process::OUT, str_repeat('{"stream_index":0},', 110_000));
            $callback(Process::OUT, str_repeat('{"stream_index":0},', 60_000));

            return 0;
        });
        $flood->method('isSuccessful')->willReturn(true);
        $result = $this->probing($flood, $logger)->inspect($path);
        self::assertSame(['blocked', 'voice_file_invalid'], [$result->state, $result->errorCode]);
        self::assertCount(1, $messages);

        // The run fails and stopping the process fails too: the file is left to retry, nothing escapes.
        $broken = $this->createMock(Process::class);
        $broken->method('setTimeout')->willReturnSelf();
        $broken->method('run')->willThrowException(new \RuntimeException('timed out'));
        // Only the clean-up stop fails; the fake's own destructor stops it again later, which must not throw.
        $stops = 0;
        $broken->method('stop')->willReturnCallback(function () use (&$stops) {
            if ($stops++ === 0) {
                throw new \RuntimeException('cannot stop');
            }

            return 0;
        });
        $result = $this->probing($broken)->inspect($path);
        self::assertSame(['unavailable', 'voice_check_failed'], [$result->state, $result->errorCode]);
    }

    /** An inspector whose ffprobe is the given fake process. */
    private function probing(Process $process, ?AbstractLogger $logger = null): ChatVoiceInspector
    {
        return new class(new Repository(['chat' => ['files' => ['voice_probe_binary' => PHP_BINARY]]]), $process, $logger) extends ChatVoiceInspector
        {
            public function __construct(Repository $config, private Process $process, ?AbstractLogger $logger)
            {
                parent::__construct($config, null, $logger);
            }

            protected function makeProcess(array $command): Process
            {
                return $this->process;
            }
        };
    }

    /** [checker verdict, ffprobe verdict, combined verdict, whether a disagreement is logged]. */
    public static function opinions(): array
    {
        $ready = fn (int $ms, string $mime = 'audio/webm') => new ChatVoiceInspection('ready', $mime, $ms);
        $blocked = fn (string $code = 'voice_file_invalid') => new ChatVoiceInspection('blocked', errorCode: $code);
        $unavailable = new ChatVoiceInspection('unavailable', errorCode: 'voice_check_failed');

        return [
            'agree, longer length kept' => [$ready(5000), $ready(5018), $ready(5018), false],
            'lengths 21 ms apart' => [$ready(5000), $ready(5021), $blocked(), true],
            'different formats' => [$ready(5000), $ready(5000, 'audio/ogg'), $blocked(), true],
            'ffprobe finds it too long' => [$ready(120_490), $blocked('voice_too_long'), $blocked('voice_too_long'), true],
            'ffprobe refuses it' => [$ready(5000), $blocked(), $blocked(), true],
            'checker refuses what ffprobe accepts' => [$blocked(), $ready(5000), $blocked(), true],
            'both refuse' => [$blocked('voice_too_long'), $blocked(), $blocked('voice_too_long'), false],
            'ffprobe could not run' => [$ready(5000), $unavailable, $unavailable, false],
        ];
    }

    #[DataProvider('opinions')]
    public function test_a_disagreement_with_ffprobe_blocks_the_file_and_is_logged(ChatVoiceInspection $own, ChatVoiceInspection $second, ChatVoiceInspection $expected, bool $logged): void
    {
        $messages = [];
        $logger = new class($messages) extends AbstractLogger
        {
            public function __construct(private array &$messages) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = [$level, $context];
            }
        };
        $result = (new ChatVoiceInspector(new Repository, null, $logger))->agree($own, $second);
        self::assertEquals($expected, $result);
        self::assertSame($logged, $messages !== []);
        if ($logged) {
            self::assertSame('warning', $messages[0][0]);
            self::assertSame(['checker', 'ffprobe'], array_keys($messages[0][1]));
            self::assertStringNotContainsString(sys_get_temp_dir(), json_encode($messages));
        }
    }

    private function temporary(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oblivion-voice-');
        file_put_contents($path, $bytes);
        $this->paths[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_stored_voice_bytes_must_match_the_checked_digest(): void
    {
        $file = (object) ['media_kind' => 'voice_note', 'mime_type' => 'audio/webm', 'duration_ms' => 1000, 'media_sha256' => hash_file('sha256', __FILE__)];
        self::assertTrue(ChatVoiceInspector::storedBytesMatch($file, __FILE__));
        $file->media_sha256 = str_repeat('a', 64);
        self::assertFalse(ChatVoiceInspector::storedBytesMatch($file, __FILE__));
    }
}
