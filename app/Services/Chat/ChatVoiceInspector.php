<?php

namespace App\Services\Chat;

use App\Services\Chat\Voice\ChatVoiceChecker;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Inspects only server-resolved private files; no remote URLs or client-reported duration.
 *
 * Oblivion's own voice-note checker decides every file. When ffprobe is configured it runs as well,
 * as a second opinion: both must agree on the format and on the length (within AGREEMENT_MS), and a
 * disagreement blocks the file and is logged for the service owner.
 */
class ChatVoiceInspector
{
    public const MAX_SECONDS = 120;

    public const MAX_BYTES = 20 * 1024 * 1024;

    /** The largest length difference allowed between the checker and the ffprobe second opinion. */
    public const AGREEMENT_MS = 20;

    private ChatVoiceChecker $checker;

    public function __construct(private Repository $config, ?ChatVoiceChecker $checker = null, private ?LoggerInterface $logger = null)
    {
        $this->checker = $checker ?? new ChatVoiceChecker;
    }

    /** The checker is part of Oblivion, so voice notes no longer depend on an outside program. */
    public function configured(): bool
    {
        return true;
    }

    /** Whether the optional ffprobe second opinion is set up on this server. */
    public function secondOpinionConfigured(): bool
    {
        $binary = trim((string) $this->config->get('chat.files.voice_probe_binary', ''));

        return $binary !== '' && is_file($binary) && is_executable($binary);
    }

    public function inspect(string $serverPath): ChatVoiceInspection
    {
        $size = is_file($serverPath) && is_readable($serverPath) ? @filesize($serverPath) : false;
        if ($size === false) {
            return new ChatVoiceInspection('unavailable', errorCode: 'voice_check_failed');
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return new ChatVoiceInspection('blocked', errorCode: 'voice_file_invalid');
        }
        $bytes = @file_get_contents($serverPath, false, null, 0, self::MAX_BYTES + 1);
        if ($bytes === false) {
            return new ChatVoiceInspection('unavailable', errorCode: 'voice_check_failed');
        }
        $own = $this->checker->check($bytes);
        if (! $this->secondOpinionConfigured()) {
            return $own;
        }

        return $this->agree($own, $this->secondOpinion($serverPath));
    }

    /** Runs the configured ffprobe on the file. Only meaningful when secondOpinionConfigured() is true. */
    public function secondOpinion(string $serverPath): ChatVoiceInspection
    {
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

    /** Combines the checker's verdict with ffprobe's. Either one blocking blocks; a failed second opinion leaves the file to retry. */
    public function agree(ChatVoiceInspection $own, ChatVoiceInspection $second): ChatVoiceInspection
    {
        $invalid = new ChatVoiceInspection('blocked', errorCode: 'voice_file_invalid');
        if ($own->state === 'unavailable') {
            return $own;
        }
        if ($own->state === 'blocked') {
            if ($second->state === 'ready') {
                $this->report($own, $second);
            }

            return $own;
        }
        if ($second->state === 'unavailable') {
            return $second;
        }
        if ($second->state === 'blocked') {
            $this->report($own, $second);

            return $second->errorCode === 'voice_too_long' ? $second : $invalid;
        }
        if ($second->mime !== $own->mime || abs((int) $second->durationMs - (int) $own->durationMs) > self::AGREEMENT_MS) {
            $this->report($own, $second);

            return $invalid;
        }

        return new ChatVoiceInspection('ready', $own->mime, max((int) $own->durationMs, (int) $second->durationMs));
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

    /** Logs a disagreement with verdicts only: no path, name or file content. */
    private function report(ChatVoiceInspection $own, ChatVoiceInspection $second): void
    {
        $verdict = fn (ChatVoiceInspection $result) => ['state' => $result->state, 'mime' => $result->mime, 'duration_ms' => $result->durationMs, 'error_code' => $result->errorCode];
        $this->logger?->warning('Chat voice checks disagreed; the voice message was blocked.', ['checker' => $verdict($own), 'ffprobe' => $verdict($second)]);
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
