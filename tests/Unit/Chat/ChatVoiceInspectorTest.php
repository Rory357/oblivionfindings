<?php

namespace Tests\Unit\Chat;

use App\Services\Chat\ChatVoiceInspector;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ChatVoiceInspectorTest extends TestCase
{
    private function audio(string $format = 'matroska,webm', string $codec = 'opus'): array
    {
        return ['format' => ['format_name' => $format], 'streams' => [['index' => 0, 'codec_type' => 'audio', 'codec_name' => $codec]],
            'packets' => [['stream_index' => 0, 'pts_time' => '-0.007', 'duration_time' => '0.020'], ['stream_index' => 0, 'pts_time' => '0.013', 'duration_time' => '0.040']]];
    }

    public static function formats(): array
    {
        return [['matroska,webm', 'opus', 'audio/webm'], ['ogg', 'opus', 'audio/ogg'], ['ogg', 'vorbis', 'audio/ogg'], ['mov,mp4,m4a,3gp,3g2,mj2', 'aac', 'audio/mp4']];
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
        return array_map(fn ($value) => [$value], ['video', 'extra stream', 'unknown codec', 'unknown format', 'missing duration', 'empty packets', 'foreign packet', 'invalid packet', 'negative duration', 'infinite duration', 'invalid streams']);
    }

    #[DataProvider('invalidCases')]
    public function test_rejects_non_audio_or_unverifiable_media(string $case): void
    {
        $data = $this->audio();
        match ($case) {
            'video' => $data['streams'][0]['codec_type'] = 'video',
            'extra stream' => $data['streams'][] = ['index' => 1, 'codec_type' => 'video', 'codec_name' => 'vp8'],
            'unknown codec' => $data['streams'][0]['codec_name'] = 'unknown',
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

    public function test_missing_service_cannot_claim_an_audio_verdict(): void
    {
        self::assertSame('unavailable', (new ChatVoiceInspector(new Repository))->inspect(__FILE__)->state);
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
        self::assertSame('ready', $inspector->inspect(__FILE__)->state);
        self::assertContains('-protocol_whitelist', $inspector->command);
        self::assertSame('file', $inspector->command[array_search('-protocol_whitelist', $inspector->command) + 1]);
        self::assertSame(__FILE__, $inspector->command[array_key_last($inspector->command)]);
    }

    public function test_stored_voice_bytes_must_match_the_checked_digest(): void
    {
        $file = (object) ['media_kind' => 'voice_note', 'mime_type' => 'audio/webm', 'duration_ms' => 1000, 'media_sha256' => hash_file('sha256', __FILE__)];
        self::assertTrue(ChatVoiceInspector::storedBytesMatch($file, __FILE__));
        $file->media_sha256 = str_repeat('a', 64);
        self::assertFalse(ChatVoiceInspector::storedBytesMatch($file, __FILE__));
    }
}
