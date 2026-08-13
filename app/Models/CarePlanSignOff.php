<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CarePlanSignOff extends Model
{
    use HasFactory, WritesLegacyOrganizationStorageContext;

    public const STATE_DIRECT_AUTHENTICATED = 'direct_authenticated';

    public const STATE_WITNESSED = 'witnessed';

    public const STATE_AUTHORISED_REPRESENTATIVE = 'authorised_representative';

    public const STATE_DECLINED = 'declined';

    public const STATE_UNAVAILABLE = 'unavailable';

    public const STATE_NOT_REQUIRED = 'not_required';

    public const STATE_RECORDED_PROXY = 'recorded_proxy';

    public const ATTESTATION_STATES = [
        self::STATE_DIRECT_AUTHENTICATED,
        self::STATE_WITNESSED,
        self::STATE_AUTHORISED_REPRESENTATIVE,
        self::STATE_DECLINED,
        self::STATE_UNAVAILABLE,
        self::STATE_NOT_REQUIRED,
        self::STATE_RECORDED_PROXY,
    ];

    /** Who can be recorded as agreeing to a plan. */
    public const PARTY_ROLES = ['client', 'whanau', 'eor_guardian', 'key_worker', 'clinician', 'nasc', 'other'];

    /** How the agreement was reached / recorded. */
    public const METHODS = ['in_person', 'verbal', 'email', 'hui', 'portal'];

    protected $fillable = [
        'care_plan_id',
        'attestation_state',
        'signer_type',
        'signer_user_id',
        'signer_client_id',
        'authority_next_of_kin_id',
        'capacity_evidence_consent_id',
        'clinical_credential_id',
        'authority_basis',
        'capacity_basis',
        'evidence_type',
        'evidence_reference',
        'party_role',
        'party_name',
        'relationship',
        'agreed_on',
        'method',
        'acknowledgement',
        'outcome_reason',
        'plan_version',
        'plan_version_digest',
        'digest_algorithm',
        'digest_payload_version',
        'policy_snapshot',
        'identity_provenance',
        'signer_fingerprint',
        'submission_fingerprint',
        'active_identity_key',
        'gate_satisfying',
        'signer_asserted_at',
        'recorded_by',
        'witnessed_by',
        'witnessed_at',
        'superseded_at',
        'superseded_reason',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
    ];

    protected $casts = [
        'agreed_on' => 'date',
        'plan_version' => 'integer',
        'digest_payload_version' => 'integer',
        'policy_snapshot' => 'array',
        'identity_provenance' => 'array',
        'gate_satisfying' => 'boolean',
        'signer_asserted_at' => 'datetime',
        'witnessed_at' => 'datetime',
        'superseded_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'active_identity_key',
        'signer_fingerprint',
        'submission_fingerprint',
        'identity_provenance',
        'policy_snapshot',
    ];

    protected static function booted(): void
    {
        static::updating(function (CarePlanSignOff $signOff): void {
            $mutable = [
                'active_identity_key',
                'superseded_at',
                'superseded_reason',
                'revoked_at',
                'revoked_by',
                'revocation_reason',
                'updated_at',
            ];

            if (array_diff(array_keys($signOff->getDirty()), $mutable) !== []) {
                throw new LogicException('Care plan attestation evidence is immutable; revoke and replace it instead.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Care plan attestation evidence cannot be deleted; revoke it instead.');
        });
    }

    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    public function signerClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'signer_client_id');
    }

    public function authorityNextOfKin(): BelongsTo
    {
        return $this->belongsTo(NextOfKin::class, 'authority_next_of_kin_id');
    }

    public function capacityEvidenceConsent(): BelongsTo
    {
        return $this->belongsTo(ClientConsent::class, 'capacity_evidence_consent_id');
    }

    public function clinicalCredential(): BelongsTo
    {
        return $this->belongsTo(StaffCredential::class, 'clinical_credential_id');
    }

    public function witness(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witnessed_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isCurrentFor(CarePlan $carePlan, string $digest): bool
    {
        return $this->care_plan_id === $carePlan->id
            && hash_equals((string) $this->plan_version_digest, $digest)
            && $this->revoked_at === null
            && $this->superseded_at === null;
    }
}
