<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One immutable completion generation of the existing canonical IT task. */
class ItWorkTaskCompletion extends Model
{
    public const SOURCE_COMMAND = 'command';

    public const SOURCE_LEGACY = 'legacy_snapshot';

    public $timestamps = false;

    protected $fillable = [
        'task_id', 'sequence', 'source', 'recorded_at', 'completed_at',
        'completed_by_user_id', 'recorded_by_user_id', 'task_definition',
        'prerequisite_completions', 'approval_id', 'completion_note', 'evidence',
    ];

    protected $casts = [
        'task_id' => 'integer', 'sequence' => 'integer', 'approval_id' => 'integer',
        'completed_by_user_id' => 'integer', 'recorded_by_user_id' => 'integer',
        'recorded_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
        'task_definition' => 'array', 'prerequisite_completions' => 'array', 'evidence' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Task completion history is immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Task completion history cannot be deleted.');
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ItWorkTask::class, 'task_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ItTicketApproval::class, 'approval_id');
    }
}
