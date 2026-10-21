<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** Private immutable bytes; malware availability is distinct from clinical/funding verification. */
final class ReferralEvidenceFiles
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralCommand $command, private readonly MalwareScanner $scanner) {}

    /** Both staff and external submissions use the same private storage and scan boundary. */
    public function stage(UploadedFile $file): array
    {
        Validator::make(['file' => $file], ['file' => 'required|file|max:10240|mimetypes:application/pdf,image/jpeg,image/png'])->validate();
        $mime = $file->getMimeType();
        $bytes = file_get_contents($file->getRealPath());
        abort_unless(is_string($bytes) && self::matches($bytes, $mime), 422, 'Upload a complete PDF, JPEG or PNG document.');
        $path = 'referral-evidence/'.Str::uuid().'.bin';
        $disk = Storage::disk('private');
        abort_unless($disk->put($path, $bytes, ['visibility' => 'private']), 503, 'The document could not be stored. Please try again.');
        try {
            $scan = $this->scanner->scanPath($disk->path($path), config('referral-evidence.malware_scanner', []));
            $hash = hash('sha256', $bytes);
            abort_unless(hash_equals($hash, (string) hash_file('sha256', $disk->path($path))), 422, 'Document integrity changed during scanning. Upload it again.');

            return compact('mime', 'bytes', 'path', 'scan', 'hash');
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    public function upload(User $actor, CareReferral $referral, int $version, array $input, UploadedFile $file): ReferralEvidence
    {
        $data = Validator::make([...$input, 'file' => $file], [
            'title' => 'required|string|min:3|max:200', 'audience' => ['required', Rule::in(['assessment', 'funding'])],
            'category' => ['required', Rule::in(['authority', 'assessment', 'application', 'external_decision', 'agreement', 'identity', 'other'])],
            'source' => 'required|string|min:3|max:500', 'reason' => 'required|string|min:5|max:2000',
            'expires_at' => 'present|nullable|date', 'previous_version_id' => 'present|nullable|integer|min:1',
            'file' => 'required|file|max:10240|mimetypes:application/pdf,image/jpeg,image/png',
        ])->validate();
        $this->access->authorize($actor, $referral, 'evidence.manage');
        abort_unless($this->access->audience($actor, $data['audience'], true), 403);
        $this->canCollect($referral);
        $expiry = $data['expires_at'] ? ReferralDates::parse($data['expires_at'], 'expires_at') : null;
        abort_if($expiry?->isPast(), 422, 'Choose a future evidence expiry date.');
        ['mime' => $mime, 'bytes' => $bytes, 'path' => $path, 'scan' => $scan, 'hash' => $hash] = $this->stage($file);
        $disk = Storage::disk('private');
        try {
            return $this->command->run($actor, $referral->id, $version, 'evidence.manage', function (CareReferral $current, User $actor) use ($data, $expiry, $mime, $bytes, $path, $scan, $hash): ReferralEvidence {
                abort_unless($this->access->audience($actor, $data['audience'], true), 403);
                $this->canCollect($current);
                $previous = $data['previous_version_id'] ? ReferralEvidence::query()->where('referral_id', $current->id)->where('audience', $data['audience'])->lockForUpdate()->findOrFail($data['previous_version_id']) : null;
                abort_if($previous && ReferralEvidence::query()->where('previous_version_id', $previous->id)->exists(), 409, 'This document already has a newer version. Review the latest document before replacing it.');
                $evidence = ReferralEvidence::create([
                    'referral_id' => $current->id, 'previous_version_id' => $previous?->id, 'audience' => $data['audience'],
                    'state' => match ($scan->disposition) {
                        MalwareScanDisposition::Clean => 'available', MalwareScanDisposition::Infected => 'rejected', default => 'quarantined'
                    },
                    'disk' => 'private', 'storage_path' => $path, 'sha256' => $hash, 'size_bytes' => strlen($bytes), 'uploaded_by' => $actor->id,
                    'metadata' => ['title' => $data['title'], 'category' => $data['category'], 'source' => $data['source'], 'mime' => $mime,
                        'expires_at' => $expiry?->toISOString(), 'scanner' => $scan->scanner, 'scan_error' => $scan->errorCode, 'scanned_at' => now()->toISOString(),
                        'authority_version' => $current->authority['review']['version'] ?? null],
                ]);
                if ($previous) {
                    $previous->update(['metadata' => [...$previous->metadata, 'superseded_by_id' => $evidence->id]]);
                    app(ReferralAssessment::class)->invalidateReadiness($current, $actor, 'replacement evidence');
                }
                $this->command->record($current, $actor, 'evidence.uploaded', $evidence->audience, ['evidence_id' => $evidence->id, 'previous_version_id' => $previous?->id,
                    'title' => $data['title'], 'state' => $evidence->state, 'reason' => $data['reason']]);

                return $evidence;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    public function rescan(User $actor, int $referralId, int $version, int $evidenceId): void
    {
        $this->command->run($actor, $referralId, $version, 'evidence.manage', function (CareReferral $referral, User $actor) use ($evidenceId): void {
            $evidence = $this->record($actor, $referral, $evidenceId, true);
            abort_unless($evidence->state === 'quarantined', 422, 'Only quarantined documents can be scanned again. Rejected documents need a replacement upload.');
            $bytes = $this->bytes($evidence);
            $scanner = $evidence->metadata['scanner'] ?? null;
            if ($bytes === null) {
                $state = 'rejected';
                $scanError = 'integrity_failed';
            } else {
                $scan = $this->scanner->scanPath(Storage::disk('private')->path($evidence->storage_path), config('referral-evidence.malware_scanner', []));
                $scanner = $scan->scanner;
                $state = match ($scan->disposition) {
                    MalwareScanDisposition::Clean => 'available', MalwareScanDisposition::Infected => 'rejected', default => 'quarantined'
                };
                $scanError = $scan->errorCode;
                if ($this->bytes($evidence) === null) {
                    $state = 'rejected';
                    $scanError = 'integrity_failed';
                }
            }
            $evidence->update(['state' => $state, 'metadata' => [...$evidence->metadata, 'scanner' => $scanner, 'scan_error' => $scanError, 'scanned_at' => now()->toISOString()]]);
            $this->command->record($referral, $actor, 'evidence.rescanned', $evidence->audience, ['evidence_id' => $evidence->id, 'state' => $state]);
        });
    }

    public function record(User $actor, CareReferral $referral, int $id, bool $write = false): ReferralEvidence
    {
        $audiences = array_values(array_filter(['assessment', 'funding'], fn ($audience) => $this->access->audience($actor, $audience, $write)));

        return ReferralEvidence::query()->where('referral_id', $referral->id)->whereIn('audience', $audiences)->lockForUpdate()->findOrFail($id);
    }

    /** Reject missing, altered, over-sized and unsupported files without leaking storage details. */
    public function bytes(ReferralEvidence $evidence): ?string
    {
        if ($evidence->disk !== 'private' || ! preg_match('/^referral-evidence\/[0-9a-f-]{36}\.bin$/D', $evidence->storage_path)) {
            return null;
        }
        try {
            $disk = Storage::disk('private');
            if (! $disk->exists($evidence->storage_path) || $disk->size($evidence->storage_path) !== $evidence->size_bytes || $evidence->size_bytes > 10485760) {
                return null;
            }
            $bytes = $disk->get($evidence->storage_path);

            return is_string($bytes) && hash_equals($evidence->sha256, hash('sha256', $bytes)) && self::matches($bytes, $evidence->metadata['mime'] ?? '') ? $bytes : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function summary(ReferralEvidence $evidence): array
    {
        return ['id' => $evidence->id, 'reference' => $evidence->reference, 'title' => $evidence->metadata['title'], 'audience' => $evidence->audience,
            'category' => $evidence->metadata['category'] ?? 'other', 'source' => $evidence->metadata['source'] ?? null, 'state' => $evidence->state,
            'expires_at' => $evidence->metadata['expires_at'] ?? null, 'expired' => ! empty($evidence->metadata['expires_at']) && CarbonImmutable::parse($evidence->metadata['expires_at'])->isPast(),
            'previous_version_id' => $evidence->previous_version_id, 'superseded_by_id' => $evidence->metadata['superseded_by_id'] ?? null,
            'size_bytes' => $evidence->size_bytes, 'created_at' => $evidence->created_at->toISOString()];
    }

    private function canCollect(CareReferral $referral): void
    {
        abort_if($referral->general_enquiry || $referral->stage === 'closed', 422, 'Complete the intake and keep the referral open before collecting documents.');
        abort_unless(ReferralAssessment::scope($referral, 'collect'), 422, 'Review current permission to collect supporting information before uploading documents.');
    }

    private static function matches(string $bytes, ?string $mime): bool
    {
        if ($mime === 'application/pdf') {
            return str_starts_with($bytes, '%PDF-') && str_contains(substr($bytes, -1024), '%%EOF');
        }
        $image = @getimagesizefromstring($bytes);

        return in_array($mime, ['image/png', 'image/jpeg'], true) && is_array($image) && ($image['mime'] ?? '') === $mime;
    }
}
