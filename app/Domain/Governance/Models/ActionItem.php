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
        'title',
        'source_type',
        'source_id',
        'follow_up_key',
        'description',
        'assigned_to',
        'due_date',
        'status',
        'completed_at',
        'completed_by',
        'completion_notes',
        'completion_receipt',
        'evidence_required',
        'evidence_attachments',
        'escalated_at',
        'escalated_by',
        'escalation_reason',
        'priority',
        'created_by',
        'progress_pct',
        'progress_notes',
        'version_number',
        'blocked_at',
        'blocked_reason',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'escalated_at' => 'datetime',
        'blocked_at' => 'datetime',
        'evidence_attachments' => 'array',
        'evidence_required' => 'boolean',
        'version_number' => 'integer',
        'progress_pct' => 'integer',
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
        return app(\App\Services\References\ReferenceNumberGenerator::class)->next('ACT');
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
        return $query->whereIn('status', ['open', 'in_progress', 'blocked']);
    }

    public function scopeBlocked($query)
    {
        return $query->where('status', 'blocked');
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

    public function getTitleAttribute($value): string
    {
        return $value ?: \Illuminate\Support\Str::limit($this->description, 60);
    }

    public function updateProgress(int $pct, ?string $notes = null, ?int $expectedVersion = null): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($pct, $notes, $expectedVersion) {
            $locked = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'complete') {
                throw new \DomainException('Completed actions are closed and cannot be updated. Create a follow-up action if further work is required.');
            }

            $currentVersion = (int) ($locked->version_number ?? 1);
            if ($expectedVersion !== null && $currentVersion !== (int) $expectedVersion) {
                throw new \DomainException('Action item was modified by another user. Please reload and review the latest changes.');
            }

            $clamped = min(100, max(0, $pct));

            $locked->update([
                'progress_pct' => $clamped,
                'progress_notes' => $notes ?? $locked->progress_notes,
                'status' => $locked->status === 'blocked' ? 'blocked' : 'in_progress',
                'version_number' => $currentVersion + 1,
            ]);

            $this->refresh();
        });
        // 100% alone does NOT close the action item. Completion requires formal notes & evidence.
    }

    public function block(string $reason, ?int $expectedVersion = null): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($reason, $expectedVersion) {
            $locked = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $currentVersion = (int) ($locked->version_number ?? 1);
            if ($expectedVersion !== null && $currentVersion !== (int) $expectedVersion) {
                throw new \DomainException('Action item was modified by another user. Please reload and review the latest changes.');
            }

            if (empty(trim($reason))) {
                throw new \DomainException('A reason is required to mark an action item as blocked.');
            }

            $locked->update([
                'blocked_at' => now(),
                'blocked_reason' => trim($reason),
                'status' => 'blocked',
                'version_number' => $currentVersion + 1,
            ]);

            $this->refresh();
        });
    }

    public function unblock(?int $expectedVersion = null): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($expectedVersion) {
            $locked = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $currentVersion = (int) ($locked->version_number ?? 1);
            if ($expectedVersion !== null && $currentVersion !== (int) $expectedVersion) {
                throw new \DomainException('Action item was modified by another user. Please reload and review the latest changes.');
            }

            $locked->update([
                'blocked_at' => null,
                'blocked_reason' => null,
                'status' => 'in_progress',
                'version_number' => $currentVersion + 1,
            ]);

            $this->refresh();
        });
    }

    public function markComplete(int $userId, ?string $notes = null, ?array $evidenceFiles = null, ?int $expectedVersion = null): string
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($userId, $notes, $evidenceFiles, $expectedVersion) {
            $locked = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $currentVersion = (int) ($locked->version_number ?? 1);
            if ($expectedVersion !== null && $currentVersion !== (int) $expectedVersion) {
                throw new \DomainException('Action item was modified by another user. Please reload and review the latest changes.');
            }

            if ($locked->status === 'complete') {
                // Idempotent completion returns existing receipt
                return $locked->completion_receipt ?? ('ACT-REC-' . $locked->action_reference);
            }

            if (empty($notes) || empty(trim($notes))) {
                throw new \DomainException('Completion notes are required to complete this action item.');
            }

            $normalizedFiles = [];
            if (is_array($evidenceFiles)) {
                foreach ($evidenceFiles as $file) {
                    $filePath = is_array($file) ? ($file['path'] ?? $file['file_path'] ?? '') : (string) $file;
                    if (! empty(trim($filePath))) {
                        $normalizedFiles[] = trim($filePath);
                    }
                }
            }

            if ($locked->evidence_required && empty($normalizedFiles) && empty($locked->evidence_attachments)) {
                throw new \DomainException('Evidence documentation is required to complete this action item.');
            }

            foreach ($normalizedFiles as $filePath) {
                $exists = \Illuminate\Support\Facades\Storage::disk('local')->exists($filePath)
                    || \Illuminate\Support\Facades\Storage::disk('public')->exists($filePath)
                    || \Illuminate\Support\Facades\Storage::exists($filePath)
                    || file_exists(storage_path('app/' . $filePath))
                    || file_exists(storage_path('app/public/' . $filePath))
                    || file_exists(public_path($filePath));

                if (! $exists) {
                    throw new \DomainException("Evidence file '{$filePath}' does not exist or has not been uploaded.");
                }
            }

            $receipt = 'ACT-REC-' . $locked->action_reference . '-' . now()->format('YmdHis');

            $allAttachments = $locked->evidence_attachments ?? [];
            if (! empty($normalizedFiles)) {
                $allAttachments = array_merge($allAttachments, $normalizedFiles);
            }

            $locked->update([
                'status' => 'complete',
                'progress_pct' => 100,
                'completed_at' => now(),
                'completed_by' => $userId,
                'completion_notes' => trim($notes),
                'completion_receipt' => $receipt,
                'evidence_attachments' => $allAttachments,
                'blocked_at' => null,
                'blocked_reason' => null,
                'version_number' => $currentVersion + 1,
            ]);

            $this->refresh();

            return $receipt;
        });
    }

    public function escalate(int $userId, string $reason, ?int $expectedVersion = null): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($userId, $reason, $expectedVersion) {
            $locked = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $currentVersion = (int) ($locked->version_number ?? 1);
            if ($expectedVersion !== null && $currentVersion !== (int) $expectedVersion) {
                throw new \DomainException('Action item was modified by another user. Please reload and review the latest changes.');
            }

            if (empty(trim($reason))) {
                throw new \DomainException('An escalation reason is required.');
            }

            $locked->update([
                'escalated_at' => now(),
                'escalated_by' => $userId,
                'escalation_reason' => trim($reason),
                'priority' => $locked->priority === 'low' ? 'medium' : 'high',
                'version_number' => $currentVersion + 1,
            ]);

            $this->refresh();
        });
    }
}
