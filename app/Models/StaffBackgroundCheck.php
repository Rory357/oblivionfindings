<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffBackgroundCheck extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'user_id',
        'check_type',
        'status',
        'reference_number',
        'provider',
        'check_date',
        'issue_date',
        'expires_at',
        'disclosures_present',
        'disclosure_details',
        'conditions',
        'risk_assessed',
        'risk_assessor_id',
        'risk_assessed_at',
        'risk_assessment',
        'risk_decision',
        'certificate_path',
        'supporting_docs_path',
        'enrolled_in_update_service',
        'update_service_reference',
        'verified_by_user_id',
        'verified_at',
        'renewal_reminder_sent_at',
        'renewal_reminder_days_before',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'check_date' => 'date',
        'issue_date' => 'date',
        'expires_at' => 'date',
        'disclosures_present' => 'boolean',
        'risk_assessed' => 'boolean',
        'risk_assessed_at' => 'datetime',
        'enrolled_in_update_service' => 'boolean',
        'verified_at' => 'datetime',
        'renewal_reminder_sent_at' => 'datetime',
    ];

    /**
     * Staff member.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Risk assessor.
     */
    public function riskAssessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'risk_assessor_id');
    }

    /**
     * User who verified the check.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
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
     * Scope: Active (clear) checks.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'clear')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope: Expired checks.
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope: Expiring soon.
     */
    public function scopeExpiringSoon($query, int $days = 60)
    {
        return $query->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    /**
     * Scope: Requiring action.
     */
    public function scopeRequiringAction($query)
    {
        return $query->whereIn('status', ['pending', 'requested', 'flagged', 'renewal_due']);
    }

    /**
     * Check if background check is valid.
     */
    public function isValid(): bool
    {
        return $this->status === 'clear'
            && (!$this->expires_at || $this->expires_at->isFuture());
    }

    /**
     * Check if background check is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if expiring soon.
     */
    public function isExpiringSoon(int $days = 60): bool
    {
        return $this->expires_at
            && $this->expires_at->isFuture()
            && $this->expires_at->diffInDays(now()) <= $days;
    }
}
