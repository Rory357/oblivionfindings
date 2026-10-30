<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralDocumentRequest;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Files\MalwareScanDisposition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Limited, one-document collection grants. No portal account or canonical person is created. */
final class ReferralDocumentRequests
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralCommand $command, private readonly ReferralEvidenceFiles $files) {}

    public static function defaults(): array
    {
        return ['enabled' => false, 'maximum_days' => 7];
    }

    public function configuration(CareReferral $referral): array
    {
        $version = ReferralSettingsVersion::query()->where('state', 'published')->find($referral->settings_version_id);

        return $version?->configuration['document_requests'] ?? self::defaults();
    }

    public function issue(User $actor, CareReferral $referral, int $version, array $input): array
    {
        $data = Validator::make($input, [
            'request_key' => 'required|uuid', 'audience' => ['required', Rule::in(['assessment', 'funding'])],
            'title' => 'required|string|min:3|max:200', 'instructions' => 'required|string|min:5|max:1000',
            'category' => ['required', Rule::in(['authority', 'assessment', 'application', 'external_decision', 'agreement', 'identity', 'other'])],
            'recipient' => 'required|string|min:3|max:200', 'recipient_verification' => 'required|string|min:10|max:1000',
            'reason' => 'required|string|min:5|max:2000', 'days' => 'required|integer|min:1|max:14',
            'separate_channel_confirmed' => 'required|accepted',
        ])->validate();
        $digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $authorize = function (CareReferral $current, User $actor, CurrentAuthorizationReads $reads) use ($data): void {
            abort_unless($this->canIssue($actor, $current, $data['audience'], $reads), 403, 'Current collection, contact and document-request permissions are required.');
            $config = $this->configuration($current);
            abort_unless($config['enabled'] && $data['days'] <= $config['maximum_days'], 422, 'Document requests must be enabled in this referral’s published settings and within its expiry limit.');
        };

        return $this->command->run($actor, $referral->id, $version, 'disclose', function (CareReferral $current, User $actor, CurrentAuthorizationReads $reads) use ($data, $digest, $authorize): array {
            $authorize($current, $actor, $reads);
            $token = bin2hex(random_bytes(32));
            $passcode = str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $request = ReferralDocumentRequest::create([
                'referral_id' => $current->id, 'issued_by' => $actor->id, 'request_key' => $data['request_key'], 'request_hash' => $digest,
                'token_hash' => hash('sha256', $token), 'passcode_hash' => Hash::make($passcode), 'audience' => $data['audience'],
                'state' => 'pending', 'failed_attempts' => 0,
                'expires_at' => now()->addDays($data['days']),
                'details' => [...$data, 'authority_fingerprint' => $this->authorityFingerprint($current)],
                'credentials' => ['token' => $token, 'passcode' => $passcode],
            ]);
            $this->command->record($current, $actor, 'document_request.issued', $request->audience, [
                'request_reference' => $request->reference, 'recipient' => $data['recipient'], 'title' => $data['title'],
                'reason' => $data['reason'], 'expires_at' => $request->expires_at->toISOString(), 'delivery' => 'staff_to_arrange',
            ]);

            return $this->issued($request);
        }, replay: function (CareReferral $current, User $actor, CurrentAuthorizationReads $reads) use ($data, $digest, $authorize): ?array {
            $existing = ReferralDocumentRequest::query()->where('referral_id', $current->id)->where('request_key', $data['request_key'])->lockForUpdate()->first();
            if (! $existing) {
                return null;
            }
            $authorize($current, $actor, $reads);
            abort_unless($existing->issued_by === $actor->id && hash_equals($existing->request_hash, $digest), 409, 'This request key was already used. Refresh before creating another request.');
            abort_unless($this->usable($existing, $current, $actor, $reads) && $existing->state === 'pending', 409, 'This request is no longer open. Review its current status.');

            return $this->issued($existing);
        });
    }

    public function revoke(User $actor, CareReferral $referral, int $version, int $id, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();
        $this->command->run($actor, $referral->id, $version, 'disclose', function (CareReferral $current, User $actor) use ($id, $reason): void {
            $request = ReferralDocumentRequest::query()->where('referral_id', $current->id)->lockForUpdate()->findOrFail($id);
            abort_unless($this->access->audience($actor, $request->audience, true) && $this->access->can($actor, 'evidence.manage'), 403);
            // Revocation remains possible after authority, settings or the issuing staff member's access changes.
            abort_unless($request->state === 'pending', 409, 'Only an open request can be revoked. Received evidence is managed separately.');
            $request->update(['state' => 'revoked', 'revoked_at' => now(), 'credentials' => null]);
            $this->command->record($current, $actor, 'document_request.revoked', $request->audience, ['request_reference' => $request->reference, 'reason' => $reason]);
        });
    }

    public function inspect(string $token, string $passcode): array
    {
        return $this->external($token, $passcode, fn ($request) => $this->publicSummary($request));
    }

    public function receive(string $token, string $passcode, UploadedFile $file): array
    {
        $this->inspect($token, $passcode); // Deny invalid grants before storing or scanning supplied bytes.
        $staged = $this->files->stage($file);
        $retained = false;
        try {
            $result = $this->external($token, $passcode, function (ReferralDocumentRequest $request, CareReferral $referral) use ($staged, &$retained): array {
                if ($request->state === 'received') {
                    $prior = ReferralEvidence::query()->where('referral_id', $referral->id)->findOrFail($request->evidence_id);
                    abort_unless(hash_equals($prior->sha256, $staged['hash']), 409, 'A different document has already been received. Ask the team for a new request.');

                    return $this->publicSummary($request);
                }
                $scan = $staged['scan'];
                $evidence = ReferralEvidence::create([
                    'referral_id' => $referral->id, 'audience' => $request->audience,
                    'state' => match ($scan->disposition) {
                        MalwareScanDisposition::Clean => 'available', MalwareScanDisposition::Infected => 'rejected', default => 'quarantined',
                    },
                    'disk' => 'private', 'storage_path' => $staged['path'], 'sha256' => $staged['hash'], 'size_bytes' => strlen($staged['bytes']),
                    'uploaded_by' => null,
                    'metadata' => ['title' => $request->details['title'], 'category' => $request->details['category'],
                        'source' => 'External document request '.$request->reference, 'document_request_reference' => $request->reference,
                        'mime' => $staged['mime'], 'expires_at' => null, 'scanner' => $scan->scanner, 'scan_error' => $scan->errorCode,
                        'scanned_at' => now()->toISOString(), 'authority_version' => $referral->authority['review']['version'] ?? null],
                ]);
                $request->update(['state' => 'received', 'received_at' => now(), 'evidence_id' => $evidence->id, 'credentials' => null]);
                // The issuer granted collection; they did not upload or verify this document.
                ReferralEvent::create(['referral_id' => $referral->id, 'actor_id' => null, 'kind' => 'document_request.received',
                    'audience' => $request->audience, 'payload' => ['request_reference' => $request->reference, 'evidence_id' => $evidence->id,
                        'state' => $evidence->state, 'source' => 'external_passcode_holder'], 'occurred_at' => now()]);
                $referral->increment('lock_version');
                $retained = true;

                return $this->publicSummary($request);
            });

            return $result;
        } finally {
            if (! $retained || ! ReferralEvidence::query()->where('storage_path', $staged['path'])->exists()) {
                Storage::disk('private')->delete($staged['path']);
            }
        }
    }

    /** Failures commit their attempt count, while all successful writes share the live authorization lock. */
    private function external(string $token, string $passcode, callable $operation): array
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $token) && preg_match('/\A[0-9]{10}\z/', $passcode), 404, 'This request is unavailable. Contact the team for help.');
        $found = ReferralDocumentRequest::query()->where('token_hash', hash('sha256', $token))->first();
        abort_unless($found, 404, 'This request is unavailable. Contact the team for help.');
        $result = DB::transaction(function () use ($found, $passcode, $operation): ?array {
            $referral = CareReferral::query()->lockForUpdate()->findOrFail($found->referral_id);
            $issuer = User::query()->findOrFail($found->issued_by);
            $issuer = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($issuer, array_column(ReferralPermissions::definitions(), 'key'));

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($found, $passcode, $operation, $referral, $issuer): ?array {
                $request = ReferralDocumentRequest::query()->lockForUpdate()->findOrFail($found->id);
                if (! $this->usable($request, $referral, $issuer, $reads)) {
                    return null;
                }
                if (! Hash::check($passcode, $request->passcode_hash)) {
                    $request->increment('failed_attempts');
                    if ($request->failed_attempts >= 5) {
                        $request->update(['credentials' => null]);
                    }

                    return null;
                }

                return $operation($request, $referral);
            });
        }, 3);
        abort_if($result === null, 404, 'This request is unavailable. Check the code or contact the team for help.');

        return $result;
    }

    private function usable(ReferralDocumentRequest $request, CareReferral $referral, User $issuer, ?CurrentAuthorizationReads $reads = null): bool
    {
        return in_array($request->state, ['pending', 'received'], true) && $request->expires_at->isFuture() && $request->failed_attempts < 5
            && ($this->configuration($referral)['enabled'] ?? false) && $this->canIssue($issuer, $referral, $request->audience, $reads)
            && hash_equals($request->details['authority_fingerprint'], $this->authorityFingerprint($referral));
    }

    public function canIssue(User $actor, CareReferral $referral, string $audience, ?CurrentAuthorizationReads $reads = null): bool
    {
        return $actor->approved_at && $this->access->query($actor, $reads)->whereKey($referral->id)->exists()
            && $this->access->can($actor, 'disclose') && $this->access->can($actor, 'communications.manage') && $this->access->can($actor, 'evidence.manage')
            && $this->access->audience($actor, $audience, true) && ! $referral->general_enquiry && $referral->stage !== 'closed'
            && ReferralAssessment::scope($referral, 'collect') && ReferralAssessment::scope($referral, 'contact');
    }

    private function authorityFingerprint(CareReferral $referral): string
    {
        return hash('sha256', json_encode([$referral->authority, $referral->identity, $referral->intake['submitted_contact'] ?? []], JSON_THROW_ON_ERROR));
    }

    public function summary(ReferralDocumentRequest $request, ?CareReferral $referral = null): array
    {
        $state = $request->state;
        if ($state === 'pending') {
            $issuer = $referral ? User::query()->find($request->issued_by) : null;
            $state = $request->expires_at->isPast() ? 'expired' : ($request->failed_attempts >= 5 ? 'locked' : ($referral && (! $issuer || ! $this->usable($request, $referral, $issuer)) ? 'unavailable' : 'pending'));
        }

        return ['id' => $request->id, 'reference' => $request->reference, 'audience' => $request->audience, 'title' => $request->details['title'],
            'recipient' => $request->details['recipient'], 'state' => $state,
            'expires_at' => $request->expires_at->toISOString(), 'received_at' => $request->received_at?->toISOString(), 'revoked_at' => $request->revoked_at?->toISOString(), 'evidence_id' => $request->evidence_id];
    }

    private function issued(ReferralDocumentRequest $request): array
    {
        return [...$this->summary($request), 'token' => $request->credentials['token'], 'passcode' => $request->credentials['passcode']];
    }

    private function publicSummary(ReferralDocumentRequest $request): array
    {
        return ['reference' => $request->reference, 'title' => $request->details['title'], 'instructions' => $request->details['instructions'],
            'state' => $request->state, 'expires_at' => $request->expires_at->toISOString(), 'received_at' => $request->received_at?->toISOString()];
    }
}
