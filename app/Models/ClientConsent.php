<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientConsent extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'consent_type_id',
        'consent_type_version_id',
        'consent_request_id',
        'decision_evidence_digest',
        'status',
        'given_at',
        'given_by_user_id',
        'given_by_relationship',
        'given_method',
        'given_notes',
        'capacity_assessed',
        'capacity_outcome',
        'capacity_assessor_id',
        'capacity_assessed_at',
        'capacity_notes',
        'best_interests_decision',
        'best_interests_decision_maker_id',
        'best_interests_decision_at',
        'best_interests_rationale',
        'best_interests_consultees',
        'refused_at',
        'refusal_reason',
        'withdrawn_at',
        'withdrawn_by_user_id',
        'withdrawal_reason',
        'withdrawal_acknowledged',
        'expires_at',
        'renewal_reminder_sent_at',
        'superseded_by_consent_id',
        'signed_document_path',
        'evidence_type',
        'conditions',
        'special_conditions',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'given_at' => 'datetime',
        'capacity_assessed_at' => 'datetime',
        'best_interests_decision_at' => 'datetime',
        'refused_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'expires_at' => 'datetime',
        'renewal_reminder_sent_at' => 'datetime',
        'capacity_assessed' => 'boolean',
        'best_interests_decision' => 'boolean',
        'best_interests_consultees' => 'array',
        'conditions' => 'array',
    ];

    /**
     * Client.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Consent type.
     */
    public function consentType(): BelongsTo
    {
        return $this->belongsTo(ConsentType::class);
    }

    /**
     * Consent type version.
     */
    public function consentTypeVersion(): BelongsTo
    {
        return $this->belongsTo(ConsentTypeVersion::class);
    }

    public function consentRequest(): BelongsTo
    {
        return $this->belongsTo(ConsentRequest::class);
    }

    /**
     * User who recorded the consent.
     */
    public function givenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by_user_id');
    }

    /**
     * Capacity assessor.
     */
    public function capacityAssessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capacity_assessor_id');
    }

    /**
     * Best interests decision maker.
     */
    public function bestInterestsDecisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'best_interests_decision_maker_id');
    }

    /**
     * User who withdrew the consent.
     */
    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by_user_id');
    }

    /**
     * Consent that superseded this one.
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(ClientConsent::class, 'superseded_by_consent_id');
    }

    /**
     * User who created the record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last updated the record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: Active consents (given and not withdrawn/expired).
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'given')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope: Expiring soon.
     */
    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('status', 'given')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    /**
     * Scope: Expired consents.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'given')
            ->where('expires_at', '<=', now());
    }

    /**
     * Check if consent is currently valid.
     */
    public function isValid(): bool
    {
        return $this->status === 'given'
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    /**
     * Check if consent is expired.
     */
    public function isExpired(): bool
    {
        return $this->status === 'given'
            && $this->expires_at
            && $this->expires_at->isPast();
    }

    /**
     * Check if consent is expiring soon.
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->status === 'given'
            && $this->expires_at
            && $this->expires_at->isFuture()
            && $this->expires_at->diffInDays(now()) <= $days;
    }

    /**
     * Check if consent was given under best interests.
     */
    public function wasGivenUnderBestInterests(): bool
    {
        return $this->best_interests_decision;
    }

    /**
     * Check if client has capacity.
     */
    public function clientHasCapacity(): bool
    {
        return $this->capacity_assessed
            && $this->capacity_outcome === 'has_capacity';
    }
}
