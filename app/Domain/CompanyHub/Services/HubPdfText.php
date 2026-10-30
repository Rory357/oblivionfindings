<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubMedia;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/** Internal text-only index; this cache never grants document access or supplies public text. */
class HubPdfText
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    private ?float $deadline = null;

    private bool $incomplete = false;

    public function incomplete(): bool
    {
        return $this->incomplete;
    }

    public function text(HubMedia $media): string
    {
        if ($media->mime !== 'application/pdf') {
            return '';
        }
        $this->deadline ??= microtime(true) + 5;
        try {
            $stream = Storage::disk('private')->readStream($media->path);
            if (! is_resource($stream)) {
                return '';
            }
            try {
                $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
            } finally {
                fclose($stream);
            }
            if (! is_string($bytes) || strlen($bytes) > self::MAX_BYTES || ! str_starts_with($bytes, '%PDF-')) {
                return '';
            }
            $key = 'hub-pdf-text:2.13.1:'.hash('sha256', $bytes);
            $cache = Cache::store('file');
            $cached = $cache->get($key);
            if (is_string($cached)) {
                return $cached;
            }
            $remaining = $this->deadline - microtime(true);
            $php = (new PhpExecutableFinder)->find(false);
            if ($remaining <= 0 || ! $php) {
                $this->incomplete = true;

                return '';
            }
            $process = new Process([$php, '-d', 'memory_limit=96M', __DIR__.'/../Support/extract-pdf-text.php'], base_path());
            $process->setInput($bytes);
            $process->setTimeout(min(4, $remaining));
            $process->run();
            $text = $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;
            $text = is_string($text) ? mb_substr($text, 0, 250000) : '';
            $cache->put($key, $text, now()->addDays(7));

            return $text;
        } catch (ProcessTimedOutException) {
            $this->incomplete = true;

            return '';
        } catch (\Throwable) {
            // An unavailable worker or cache must not turn partial text coverage into a definitive empty result.
            $this->incomplete = true;

            return '';
        }
    }
}
