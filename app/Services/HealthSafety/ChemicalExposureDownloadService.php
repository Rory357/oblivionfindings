<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** A checked private descriptor never becomes JSON; only one verified opened stream is emitted. */
final class ChemicalExposureDownloadService
{
    public function __construct(private readonly ChemicalExposureEvidenceService $evidence) {}

    public function download(User $viewer, int $exposureId, int $evidenceId): StreamedResponse
    {
        $descriptor = $this->evidence->evidenceReadProof($viewer, $exposureId, $evidenceId);
        $proof = $descriptor['byte_proof'];
        if (($descriptor['disk'] ?? null) !== 'private' || ! is_array($proof) || ! is_int($proof['size'] ?? null)
            || $proof['size'] < 1 || ! is_string($proof['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $proof['sha256']) !== 1) {
            throw new LogicException('Exposure evidence has no verified private byte descriptor.');
        }
        $input = Storage::disk('private')->readStream($descriptor['path']);
        abort_unless(is_resource($input), 404);
        $spool = fopen('php://temp/maxmemory:1048576', 'w+');
        if ($spool === false) { fclose($input); throw new LogicException('Private evidence could not be prepared.'); }
        try {
            $copied = stream_copy_to_stream($input, $spool);
            // A plain-file copy can stop at the known size without a read reaching EOF, so feof() alone stays false.
            // One more read must return nothing and reach EOF: no bytes were left behind.
            $trailing = $copied === false ? false : fread($input, 1);
            if ($copied === false || $trailing !== '' || ! feof($input) || ! rewind($spool)) { throw new LogicException('Private evidence could not be fully copied.'); }
            $hash = hash_init('sha256'); $hashed = hash_update_stream($hash, $spool);
            abort_unless($copied === $proof['size'] && $hashed === $proof['size'] && hash_equals($proof['sha256'], hash_final($hash)), 404);
            if (! rewind($spool)) { throw new LogicException('Verified private evidence could not be replayed.'); }
        } catch (Throwable $failure) { fclose($spool); throw $failure; }
        finally { fclose($input); }
        return response()->streamDownload(function () use ($spool): void {
            try { if (fpassthru($spool) === false) { throw new LogicException('Verified private evidence transmission failed.'); } }
            finally { fclose($spool); }
        }, $descriptor['original_name'], ['Content-Type' => $descriptor['mime'] ?: 'application/octet-stream',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'private, no-store, no-cache, must-revalidate']);
    }
}
