<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ActionItem extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'action_reference',
        'source_type',
        'source_id',
        'description',
        'assigned_to',
        'due_date',
        'status',
        'completed_at',
        'completed_by',
        'completion_notes',
        'evidence_required',
        'evidence_attachments',
        'escalated_at',
        'escalated_by',
        'escalation_reason',
        'priority',
        'created_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'escalated_at' => 'datetime',
        'evidence_attachments' => 'array',
        'evidence_required' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->action_reference)) {
                $model->action_reference = static::generateReference();
            }
        });
    }

    public static function generateReference(): string
    {
        $year = now()->year;
        $prefix = "ACT-{$year}-";
        $last = static::whereYear('created_at', $year)->count() + 1;
        return $prefix . str_pad($last, 4, '0', STR_PAD_LEFT);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function source()
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
            ->whereIn('status', ['open', 'in_progress']);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', ['high', 'critical']);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'in_progress']);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date->isPast();
    }

    public function daysUntilDue(): int
    {
        return now()->diffInDays($this->due_date, false);
    }

    public function markComplete(int $userId, ?string $notes = null): void
    {
        $this->update([
            'status' => 'complete',
            'completed_at' => now(),
            'completed_by' => $userId,
            'completion_notes' => $notes,
        ]);
    }

    public function escalate(int $userId, string $reason): void
    {
        $this->update([
            'escalated_at' => now(),
            'escalated_by' => $userId,
            'escalation_reason' => $reason,
            'priority' => $this->priority === 'low' ? 'medium' : 'high',
        ]);
    }
}
