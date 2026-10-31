<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmergencyDrillFinding extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'emergency_drill_id',
        'finding_type',
        'description',
        'severity',
        'status',
        'corrective_action',
        'assigned_to',
        'due_date',
        'resolved_at',
        'resolution_notes',
        'resolution_evidence_ids',
        'hs_corrective_action_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'resolution_evidence_ids' => 'array',
        'hs_corrective_action_id' => 'integer',
        'due_date' => 'date',
        'resolved_at' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function emergencyDrill(): BelongsTo
    {
        return $this->belongsTo(EmergencyDrill::class);
    }

    public function correctiveAction(): BelongsTo
    {
        return $this->belongsTo(HsCorrectiveAction::class, 'hs_corrective_action_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString())
            ->whereNotIn('status', ['resolved', 'closed', 'accepted']);
    }

    public function scopeOfSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }
}
