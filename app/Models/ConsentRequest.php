<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pending "please review and sign" ask, sent from staff to a family-portal
 * signatory. On approval, this spawns a ClientConsent row and (when
 * triggered by a device assignment) activates the draft DeviceAssignment.
 */
class ConsentRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const RELATION_SELF = 'client';

    public const RELATION_NEXT_OF_KIN = 'next_of_kin';

    public const RELATION_WELFARE_GUARDIAN = 'welfare_guardian';

    public const RELATION_EPOA_PERSONAL_CARE = 'epoa_personal_care';

    public const RELATION_PARENT_GUARDIAN = 'parent_guardian';

    public const RELATION_COURT_APPOINTED = 'court_appointed';

    /**
     * Relationship types authorised to give substituted consent under the
     * PPPR Act 1988 and related authority. Staff composing a request should
     * pick from this set; next_of_kin alone is NOT authority to consent,
     * they can only be "informed" rather than "decide".
     */
    public const AUTHORISED_SUBSTITUTE_RELATIONS = [
        self::RELATION_WELFARE_GUARDIAN,
        self::RELATION_EPOA_PERSONAL_CARE,
        self::RELATION_PARENT_GUARDIAN,
        self::RELATION_COURT_APPOINTED,
    ];

    protected $fillable = [
        'client_id',
        'consent_type_id',
        'requested_by_user_id',
        'recipient_user_id',
        'recipient_relationship',
        'authority_next_of_kin_id',
        'triggering_subject_type',
        'triggering_subject_id',
        'purpose',
        'least_restrictive_justification',
        'data_scope',
        'retention_period_days',
        'withdrawal_method_text',
        'staff_notes',
        'status',
        'sent_at',
        'viewed_at',
        'responded_at',
        'expires_at',
        'response_notes',
        'response_ip_address',
        'response_user_agent',
        'resulting_consent_id',
        'cancelled_by_user_id',
        'cancellation_reason',
        'audit_trail',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'responded_at' => 'datetime',
        'expires_at' => 'datetime',
        'retention_period_days' => 'integer',
        'audit_trail' => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function consentType(): BelongsTo
    {
        return $this->belongsTo(ConsentType::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function authorityNextOfKin(): BelongsTo
    {
        return $this->belongsTo(NextOfKin::class, 'authority_next_of_kin_id');
    }

    public function triggeringSubject(): MorphTo
    {
        return $this->morphTo();
    }

    public function resultingConsent(): BelongsTo
    {
        return $this->belongsTo(ClientConsent::class, 'resulting_consent_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeForRecipient(Builder $query, int $userId): Builder
    {
        return $query->where('recipient_user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING])
            ->where('expires_at', '>', now());
    }

    public function scopeOverdueForExpiry(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('expires_at', '<=', now());
    }

    // ── State helpers ─────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->isPending() && $this->expires_at?->isPast());
    }

    public function isActionable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    public function authorityToConsent(): string
    {
        return $this->authority_next_of_kin_id !== null
            && in_array($this->recipient_relationship, self::AUTHORISED_SUBSTITUTE_RELATIONS, true)
            ? 'substitute'
            : ($this->recipient_relationship === self::RELATION_SELF ? 'self' : 'informational_only');
    }
}
