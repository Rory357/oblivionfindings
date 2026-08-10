<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteHazard extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes, WritesLegacyStorageContext;

    protected $table = 'site_hazards';

    protected $fillable = [
        'site_id',
        'reference_number',
        'hazard_type',
        'custom_hazard_type',
        'severity',
        'likelihood',
        'risk_rating',
        'description',
        'location',
        'witnesses',
        'photo_paths',
        'document_paths',
        'immediate_action_taken',
        'immediate_action_applied',
        'reported_by_user_id',
        'assigned_to_user_id',
        'assigned_at',
        'status',
        'status_changed_at',
        'status_changed_by_user_id',
        'resolution_summary',
        'resolution_evidence',
        'closed_at',
        'closed_by_user_id',
        'due_date',
        'review_date',
        'linked_inspection_id',
        'linked_checklist_run_id',
        'warning_sent_at',
        'overdue_notified_at',
        'control_hierarchy',
        'residual_risk_rating',
        'residual_likelihood',
        'residual_severity',
        'control_effectiveness',
        'control_review_date',
    ];

    protected $casts = [
        'photo_paths' => 'array',
        'document_paths' => 'array',
        'resolution_evidence' => 'array',
        'immediate_action_applied' => 'boolean',
        'assigned_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'closed_at' => 'datetime',
        'due_date' => 'date',
        'review_date' => 'date',
        'warning_sent_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
        'control_hierarchy' => 'array',
        'control_review_date' => 'date',
    ];

    // Relationships
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(SiteHazardAction::class, 'hazard_id');
    }

    // Scopes
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }

    public function scopeClosed($query)
    {
        return $query->whereIn('status', ['mitigated', 'closed']);
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_rating', ['high', 'extreme']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now()->toDateString())
                     ->whereIn('status', ['open', 'in_progress']);
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to_user_id', $userId);
    }

    /** Open/in-progress hazards whose due date falls within the next 7 days. */
    public function scopeDueSoon($query)
    {
        return $query->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->whereIn('status', ['open', 'in_progress']);
    }

    /** Open/in-progress hazards with no owner. */
    public function scopeUnassignedOpen($query)
    {
        return $query->whereNull('assigned_to_user_id')
            ->whereIn('status', ['open', 'in_progress']);
    }

    /** Live critical exposure: extreme-rated or critical-severity, not yet mitigated/closed. */
    public function scopeCriticalOpen($query)
    {
        return $query->where(fn ($q) => $q->where('risk_rating', 'extreme')->orWhere('severity', 'critical'))
            ->whereIn('status', ['open', 'in_progress']);
    }

    // Helpers
    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'in_progress']);
    }

    public function isOverdue(): bool
    {
        return $this->due_date && $this->due_date < now()->toDateString() && $this->isOpen();
    }

    public function requiresAssignment(): bool
    {
        return in_array($this->risk_rating, ['high', 'extreme']) && !$this->assigned_to_user_id;
    }

    /** Due within the next 7 days while still open. */
    public function isDueSoon(): bool
    {
        if (! $this->due_date || ! $this->isOpen()) {
            return false;
        }

        return $this->due_date->betweenIncluded(now()->startOfDay(), now()->addDays(7)->endOfDay());
    }

    /**
     * WorkSafe-notifiable threshold (HSWA 2015): extreme risk rating or a
     * critical-severity hazard. Drives the register flag + detail banner.
     */
    public function isWorksafeNotifiable(): bool
    {
        return $this->risk_rating === 'extreme' || $this->severity === 'critical';
    }
}
