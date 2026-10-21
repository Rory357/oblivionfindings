<?php

namespace App\Services\Chat;

use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Process\Process;
use Throwable;

/** Inspects only server-resolved private files; no remote URLs or client-reported duration. */
class ChatVoiceInspector
{
    public const MAX_SECONDS = 120;

    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private Repository $config) {}

    public function configured(): bool
    {
        $binary = trim((string) $this->config->get('chat.files.voice_probe_binary', ''));

        return $binary !== '' && is_file($binary) && is_executable($binary);
    }

    public function inspect(string $serverPath): ChatVoiceInspection
    {
        if (! $this->configured()) {
            return new ChatVoiceInspection('unavailable', errorCode: 'voice_check_unavailable');
        }
        if (! is_file($serverPath) || ! is_readable($serverPath) || filesize($serverPath) <= 0 || filesize($serverPath) > self::MAX_BYTES) {
            return new ChatVoiceInspection('blocked', errorCode: 'voice_file_invalid');
        }
        $process = $this->makeProcess([
            trim((string) $this->config->get('chat.files.voice_probe_binary')), '-v', 'error',
            '-protocol_whitelist', 'file', '-format_whitelist', 'matroska,webm,ogg,mov',
            '-show_entries', 'format=format_name,duration:stream=index,codec_type,codec_name:packet=stream_index,pts_time,duration_time',
            '-of', 'json', '-i', $serverPath,
        ]);
        $process->setTimeout(15);
        $output = '';
        $bytes = 0;
        try {
            $process->run(function (string $type, string $chunk) use (&$output, &$bytes) {
                $bytes += strlen($chunk);
                if ($bytes > 2 * 1024 * 1024) {
                    throw new \RuntimeException('Voice inspection output limit exceeded.');
                }
                if ($type === Process::OUT) {
                    $output .= $chunk;
                }
            });
            if (! $process->isSuccessful()) {
                return new ChatVoiceInspection('blocked', errorCode: 'voice_file_invalid');
            }

            return $this->analyse(json_decode($output, true, 32, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            $process->stop(0);

            return new ChatVoiceInspection('unavailable', errorCode: 'voice_check_failed');
        }
    }

    /** Derives duration from audio packets, including browser WebM without a duration header. */
    public function analyse(mixed $data): ChatVoiceInspection
    {
        $invalid = new ChatVoiceInspection('blocked', errorCode: 'voice_file_invalid');
        if (! is_array($data) || ! is_array($data['streams'] ?? null) || count($data['streams']) !== 1) {
            return $invalid;
        }
        $stream = $data['streams'][0] ?? null;
        if (! is_array($stream) || ! is_array($data['format'] ?? null)) {
            return $invalid;
        }
        if (($stream['codec_type'] ?? null) !== 'audio' || ! is_int($stream['index'] ?? null)) {
            return $invalid;
        }
        $format = $data['format']['format_name'] ?? '';
        $codec = $stream['codec_name'] ?? '';
        $mime = match (true) {
            $format === 'matroska,webm' && $codec === 'opus' => 'audio/webm',
            $format === 'ogg' && in_array($codec, ['opus', 'vorbis'], true) => 'audio/ogg',
            $format === 'mov,mp4,m4a,3gp,3g2,mj2' && $codec === 'aac' => 'audio/mp4',
            default => null,
        };
        if (! $mime || ! is_array($data['packets'] ?? null) || ! count($data['packets'])) {
            return $invalid;
        }
        $start = INF;
        $end = -INF;
        $total = 0;
        foreach ($data['packets'] as $packet) {
            if (! is_array($packet)) {
                return $invalid;
            }
            $position = $packet['pts_time'] ?? null;
            $duration = $packet['duration_time'] ?? null;
            if (($packet['stream_index'] ?? null) !== $stream['index'] || ! is_numeric($position) || ! is_numeric($duration)
                || ! is_finite((float) $position) || ! is_finite((float) $duration) || (float) $duration <= 0) {
                return $invalid;
            }
            $start = min($start, (float) $position);
            $end = max($end, (float) $position + (float) $duration);
            $total += (float) $duration;
        }
        $duration = max($total, $end - $start);
        $declared = $data['format']['duration'] ?? null;
        if ($declared !== null && $declared !== 'N/A') {
            if (! is_numeric($declared) || ! is_finite((float) $declared) || (float) $declared <= 0) {
                return $invalid;
            }
            $duration = max($duration, (float) $declared);
        }
        // Allow only codec padding around the two-minute recording limit.
        if ($duration > self::MAX_SECONDS + 0.5) {
            return new ChatVoiceInspection('blocked', errorCode: 'voice_too_long');
        }
        if ($duration < 0.05) {
            return $invalid;
        }

        return new ChatVoiceInspection('ready', $mime, (int) ceil($duration * 1000));
    }

    protected function makeProcess(array $command): Process
    {
        return new Process($command);
    }

    public static function storedBytesMatch(object $file, string $path): bool
    {
        return ($file->media_kind ?? 'file') === 'voice_note'
            && in_array($file->mime_type, ['audio/webm', 'audio/ogg', 'audio/mp4'], true)
            && ($file->duration_ms ?? 0) >= 50 && $file->duration_ms <= 120500
            && is_string($file->media_sha256 ?? null) && is_file($path)
            && hash_equals($file->media_sha256, (string) @hash_file('sha256', $path));
    }
}
