<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RiskTreatment extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'risk_treatments';

    protected $fillable = [
        'risk_register_entry_id',
        'action_description',
        'assigned_to',
        'due_date',
        'status',
        'started_at',
        'completed_at',
        'completed_by',
        'evidence_required',
        'evidence_attachments',
        'completion_evidence',
        'expected_score_reduction',
        'score_reduced',
        'created_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'evidence_attachments' => 'array',
        'evidence_required' => 'boolean',
        'score_reduced' => 'boolean',
        'expected_score_reduction' => 'integer',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(RiskRegisterEntry::class, 'risk_register_entry_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['planned', 'in_progress']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
            ->whereIn('status', ['planned', 'in_progress']);
    }

    public function isPlanned(): bool
    {
        return $this->status === 'planned';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public function start(): void
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    public function complete(int $userId, ?string $evidence = null): void
    {
        $this->update([
            'status' => 'complete',
            'completed_at' => now(),
            'completed_by' => $userId,
            'completion_evidence' => $evidence,
        ]);

        // Update risk score if applicable
        if ($this->expected_score_reduction && !$this->score_reduced) {
            $risk = $this->risk;
            $risk->residual_score = max(1, $risk->residual_score - $this->expected_score_reduction);
            $risk->within_appetite = $risk->residual_score <= $risk->appetite_threshold;
            $risk->save();
            $this->update(['score_reduced' => true]);
        }
    }
}
