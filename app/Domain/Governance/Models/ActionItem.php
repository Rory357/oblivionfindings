<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ActionItem extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    /**
     * Plain messages members see (vocabulary.md). The stale-version message is
     * also how controllers recognise a concurrent edit, so compare against the
     * constant — never a fragment of the wording.
     */
    public const STALE_VERSION_MESSAGE = 'Someone else changed this action while you were working on it. Reload the page to see their changes, then try again.';

    public const ALREADY_DONE_MESSAGE = "This action is already done, so it can't be changed.";

    public const EVIDENCE_NEEDED_MESSAGE = "This action needs evidence before it can be marked as done. Upload a file that shows it's done, such as the signed document or a confirmation email.";

    /** Reason recorded when the overdue sweep raises an action on its own. */
    public const AUTOMATIC_ESCALATION_REASON = 'Escalated automatically because it was overdue';

    /** Reason written by the sweep before automatic escalations had no escalator. */
    public const LEGACY_AUTOMATIC_ESCALATION_REASON = 'Automatically escalated due to overdue status';

    protected $hidden = [
        // Legacy evidence stored as storage paths: never serialised to a page.
        'evidence_attachments',
    ];

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

    /** Files uploaded as proof the action is done. */
    public function evidence(): HasMany
    {
        return $this->hasMany(ActionItemEvidence::class, 'action_item_id')->orderBy('created_at')->orderBy('id');
    }

    /** True when the overdue sweep (not a person) raised the action with the board. */
    public function wasEscalatedAutomatically(): bool
    {
        if ($this->escalated_at === null) {
            return false;
        }

        // Older sweeps recorded the owner as the escalator with this reason.
        if ($this->escalation_reason === self::LEGACY_AUTOMATIC_ESCALATION_REASON) {
            return true;
        }

        return $this->escalated_by === null
            && $this->escalation_reason === self::AUTOMATIC_ESCALATION_REASON;
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
                throw new \DomainException(self::ALREADY_DONE_MESSAGE);
            }

            $currentVersion = (int) ($locked->version_number ?? 1);
            if ($expectedVersion !== null && $currentVersion !== (int) $expectedVersion) {
                throw new \DomainException(self::STALE_VERSION_MESSAGE);
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

            if ($locked->status === 'complete') {
                throw new \DomainException(self::ALREADY_DONE_MESSAGE);
            }

            $currentVersion = (int) ($locked->version_number ?? 1);
            if ($expectedVersion !== null && $currentVersion !== (int) $expectedVersion) {
                throw new \DomainException(self::STALE_VERSION_MESSAGE);
            }

            if (empty(trim($reason))) {
                throw new \DomainException("Say what's stopping the work.");
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

            if ($locked->status === 'complete') {
                throw new \DomainException(self::ALREADY_DONE_MESSAGE);
            }

            $currentVersion = (int) ($locked->version_number ?? 1);
            if ($expectedVersion !== null && $currentVersion !== (int) $expectedVersion) {
                throw new \DomainException(self::STALE_VERSION_MESSAGE);
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

    /**
     * Mark the action as done.
     *
     * Evidence can be files uploaded to this action (`$evidenceIds`, the ids
     * of its ActionItemEvidence rows) or, for older API callers, managed
     * storage paths (`$evidenceFiles`). Both are validated: uploaded ids must
     * belong to THIS action, and paths must be real managed files that no
     * other action already uses.
     *
     * @param  array<int, mixed>|null  $evidenceFiles
     * @param  array<int, mixed>|null  $evidenceIds
     */
    public function markComplete(int $userId, ?string $notes = null, ?array $evidenceFiles = null, ?int $expectedVersion = null, ?array $evidenceIds = null): string
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($userId, $notes, $evidenceFiles, $expectedVersion, $evidenceIds) {
            $locked = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $currentVersion = (int) ($locked->version_number ?? 1);
            if ($expectedVersion !== null && $currentVersion !== (int) $expectedVersion) {
                throw new \DomainException(self::STALE_VERSION_MESSAGE);
            }

            if ($locked->status === 'complete') {
                // Idempotent completion returns existing receipt
                return $locked->completion_receipt ?? ('ACT-REC-' . $locked->action_reference);
            }

            if (empty($notes) || empty(trim($notes))) {
                throw new \DomainException('Add a short note about what was done.');
            }

            $uploadedIds = [];
            if (is_array($evidenceIds)) {
                foreach ($evidenceIds as $id) {
                    if (! is_numeric($id) || (int) $id < 1) {
                        throw new \DomainException("One of the evidence files couldn't be found. Reload the page and try again.");
                    }
                    $uploadedIds[] = (int) $id;
                }
                $uploadedIds = array_values(array_unique($uploadedIds));
            }

            if ($uploadedIds !== []) {
                $belongingCount = ActionItemEvidence::query()
                    ->where('action_item_id', $locked->id)
                    ->whereIn('id', $uploadedIds)
                    ->count();

                if ($belongingCount !== count($uploadedIds)) {
                    throw new \DomainException("One of the evidence files belongs to a different action. Upload the file to this action instead.");
                }
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

            $hasUploadedEvidence = ActionItemEvidence::query()->where('action_item_id', $locked->id)->exists();

            if ($locked->evidence_required
                && empty($normalizedFiles)
                && empty($locked->evidence_attachments)
                && ! $hasUploadedEvidence) {
                throw new \DomainException(self::EVIDENCE_NEEDED_MESSAGE);
            }

            foreach ($normalizedFiles as $filePath) {
                if (file_exists(public_path($filePath)) && ! \Illuminate\Support\Facades\Storage::disk('local')->exists($filePath)) {
                    throw new \DomainException("That file can't be used as evidence. Upload the file instead.");
                }

                $diskLocal = \Illuminate\Support\Facades\Storage::disk('local');
                $diskPublic = \Illuminate\Support\Facades\Storage::disk('public');
                $exists = false;
                if ($diskLocal->exists($filePath) && (int) $diskLocal->size($filePath) > 0) {
                    $exists = true;
                } elseif ($diskPublic->exists($filePath) && (int) $diskPublic->size($filePath) > 0) {
                    $exists = true;
                }

                if (! $exists) {
                    throw new \DomainException("We couldn't find that evidence file. Upload the file instead.");
                }

                $otherAction = static::where('id', '!=', $locked->id)
                    ->whereJsonContains('evidence_attachments', $filePath)
                    ->first();
                if ($otherAction) {
                    $completingUser = \App\Models\User::find($userId);
                    if (! $completingUser || ! app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class)->canViewActionItem($completingUser, $otherAction)) {
                        throw new \DomainException("That evidence file belongs to a record you can't open. Upload your own copy instead.");
                    }
                    throw new \DomainException('That evidence file is already attached to another action. Upload your own copy instead.');
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

    /**
     * Raise the action with the board. `$userId` is the person who raised it,
     * or null when the overdue sweep does it automatically — an automatic
     * escalation never records the owner as the escalator.
     */
    public function escalate(?int $userId, string $reason, ?int $expectedVersion = null): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($userId, $reason, $expectedVersion) {
            $locked = static::where('id', $this->id)->lockForUpdate()->firstOrFail();

            $currentVersion = (int) ($locked->version_number ?? 1);
            if ($expectedVersion !== null && $currentVersion !== (int) $expectedVersion) {
                throw new \DomainException(self::STALE_VERSION_MESSAGE);
            }

            if ($locked->status === 'complete') {
                throw new \DomainException(self::ALREADY_DONE_MESSAGE);
            }

            if (empty(trim($reason))) {
                throw new \DomainException('Say why the board needs to look at this action.');
            }

            $locked->update([
                'escalated_at' => now(),
                'escalated_by' => $userId,
                'escalation_reason' => trim($reason),
                // Raise the priority one step; never lower a critical action.
                'priority' => match ($locked->priority) {
                    'low' => 'medium',
                    'critical' => 'critical',
                    default => 'high',
                },
                'version_number' => $currentVersion + 1,
            ]);

            $this->refresh();
        });
    }
}
