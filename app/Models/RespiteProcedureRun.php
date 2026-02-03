<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteProcedureRun extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'procedure_template_id',
        'subject_type',
        'subject_id',
        'status',
        'current_step',
        'total_steps',
        'step_states',
        'collected_evidence',
        'variables',
        'started_at',
        'completed_at',
        'failed_at',
        'failure_reason',
        'sla_deadline',
        'sla_breached',
        'sla_breached_at',
        'escalation_level',
        'last_escalated_at',
        'escalated_to_user_id',
        'initiated_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'step_states' => 'array',
        'collected_evidence' => 'array',
        'variables' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'sla_deadline' => 'datetime',
        'sla_breached' => 'boolean',
        'sla_breached_at' => 'datetime',
        'last_escalated_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProcedureTemplate::class, 'procedure_template_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(RespiteTask::class, 'procedure_run_id');
    }

    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to_user_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_BLOCKED]);
    }

    public function scopeOverdue($query)
    {
        return $query->where('sla_deadline', '<', now())
            ->where('sla_breached', false)
            ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    // Helper methods
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_BLOCKED]);
    }

    public function canProgress(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS]);
    }

    public function markStarted(): void
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    public function checkSlaBreached(): bool
    {
        if ($this->sla_deadline && $this->sla_deadline->isPast() && !$this->sla_breached) {
            $this->update([
                'sla_breached' => true,
                'sla_breached_at' => now(),
            ]);
            return true;
        }
        return $this->sla_breached;
    }

    public function escalate(int $userId): void
    {
        $this->update([
            'escalation_level' => $this->escalation_level + 1,
            'last_escalated_at' => now(),
            'escalated_to_user_id' => $userId,
        ]);
    }

    public function advanceStep(): void
    {
        if ($this->current_step < $this->total_steps) {
            $this->update(['current_step' => $this->current_step + 1]);
        }
    }

    public function getProgressPercentage(): int
    {
        if ($this->total_steps === 0) {
            return 0;
        }
        return (int) round(($this->current_step / $this->total_steps) * 100);
    }
}
