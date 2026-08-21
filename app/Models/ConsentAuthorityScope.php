<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class ConsentAuthorityScope extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'next_of_kin_id',
        'client_id',
        'site_id',
        'representative_user_id',
        'consent_type_id',
        'authority_type',
        'purpose',
        'version',
        'valid_from',
        'expires_at',
        'verified_at',
        'verified_by_user_id',
        'capacity_evidence_consent_id',
        'evidence_reference',
        'evidence_snapshot',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
    ];

    protected $casts = [
        'version' => 'integer',
        'valid_from' => 'datetime',
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'evidence_snapshot' => 'array',
        'revoked_at' => 'datetime',
    ];

    public function nextOfKin(): BelongsTo
    {
        return $this->belongsTo(NextOfKin::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'representative_user_id');
    }

    public function consentType(): BelongsTo
    {
        return $this->belongsTo(ConsentType::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function capacityEvidenceConsent(): BelongsTo
    {
        return $this->belongsTo(ClientConsent::class, 'capacity_evidence_consent_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function isCurrent(): bool
    {
        return $this->revoked_at === null
            && $this->verified_at?->lessThanOrEqualTo(now())
            && $this->valid_from?->lessThanOrEqualTo(now())
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function authorityEvidenceIsCurrent(): bool
    {
        $this->loadMissing('nextOfKin');
        $authority = $this->nextOfKin;
        $snapshot = $this->evidence_snapshot['authority'] ?? null;

        return $authority instanceof NextOfKin
            && is_array($snapshot)
            && (int) ($snapshot['next_of_kin_id'] ?? 0) === (int) $authority->id
            && ($snapshot['legal_authority_type'] ?? null) === $authority->legal_authority_type
            && (int) ($snapshot['verified_by_user_id'] ?? 0)
                === (int) $authority->legal_authority_verified_by_user_id
            && $this->timestampMatches(
                $snapshot['verified_at'] ?? null,
                $authority->legal_authority_verified_at,
            )
            && $this->timestampMatches(
                $snapshot['expires_at'] ?? null,
                $authority->legal_authority_expires_at,
            );
    }

    public function capacityEvidenceIsCurrent(): bool
    {
        $this->loadMissing('capacityEvidenceConsent');
        $capacity = $this->capacityEvidenceConsent;
        $snapshot = $this->evidence_snapshot['capacity'] ?? null;

        return $capacity instanceof ClientConsent
            && is_array($snapshot)
            && (int) ($snapshot['client_consent_id'] ?? 0) === (int) $capacity->id
            && ($snapshot['outcome'] ?? null) === $capacity->capacity_outcome
            && (int) ($snapshot['assessor_user_id'] ?? 0) === (int) $capacity->capacity_assessor_id
            && $this->timestampMatches(
                $snapshot['assessed_at'] ?? null,
                $capacity->capacity_assessed_at,
            );
    }

    private function timestampMatches(mixed $snapshot, ?CarbonInterface $current): bool
    {
        if ($snapshot === null || $snapshot === '') {
            return $current === null;
        }

        if ($current === null || ! is_string($snapshot)) {
            return false;
        }

        try {
            return Carbon::parse($snapshot)->equalTo($current);
        } catch (Throwable) {
            return false;
        }
    }
}
