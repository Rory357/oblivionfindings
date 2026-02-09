<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteHazard extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $table = 'site_hazards';

    protected $fillable = [
        'site_id',
        'tenant_id',
        'reference_number',
        'hazard_type',
        'custom_hazard_type',
        'severity',
        'likelihood',
        'risk_rating',
        'description',
        'photo_paths',
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
    ];

    protected $casts = [
        'photo_paths' => 'array',
        'resolution_evidence' => 'array',
        'immediate_action_applied' => 'boolean',
        'assigned_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'closed_at' => 'datetime',
        'due_date' => 'date',
        'review_date' => 'date',
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
}
