<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SafeguardingInvestigation extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'safeguarding_concern_id',
        'investigation_type',
        'lead_investigator_id',
        'investigation_team',
        'started_at',
        'target_completion_date',
        'completed_at',
        'status',
        'terms_of_reference',
        'methodology',
        'evidence_collected',
        'interviews_conducted',
        'findings',
        'outcome',
        'recommendations',
        'action_plan',
        'report_completed',
        'report_path',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'investigation_team' => 'array',
        'evidence_collected' => 'array',
        'interviews_conducted' => 'array',
        'action_plan' => 'array',
        'started_at' => 'datetime',
        'target_completion_date' => 'datetime',
        'completed_at' => 'datetime',
        'report_completed' => 'boolean',
    ];

    /**
     * Safeguarding concern.
     */
    public function concern(): BelongsTo
    {
        return $this->belongsTo(SafeguardingConcern::class, 'safeguarding_concern_id');
    }

    /**
     * Lead investigator.
     */
    public function leadInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_investigator_id');
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
     * Scope: Active investigations.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['planned', 'in_progress']);
    }

    /**
     * Scope: Overdue investigations.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', '!=', 'completed')
            ->where('target_completion_date', '<', now());
    }

    /**
     * Check if investigation is complete.
     */
    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if investigation is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->status !== 'completed'
            && $this->target_completion_date
            && $this->target_completion_date->isPast();
    }
}
