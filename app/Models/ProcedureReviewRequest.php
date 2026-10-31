<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureReviewRequest extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['lock_version' => 'integer', 'requested_at' => 'datetime', 'decided_at' => 'datetime'];

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(SafeWorkProcedure::class, 'safe_work_procedure_id');
    }

    public function requestedVersion(): BelongsTo
    {
        return $this->belongsTo(SafeWorkProcedureVersion::class, 'requested_version_id');
    }

    public function resultVersion(): BelongsTo
    {
        return $this->belongsTo(SafeWorkProcedureVersion::class, 'result_version_id');
    }

    public function hsEvent(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class);
    }

    public function hsCorrectiveAction(): BelongsTo
    {
        return $this->belongsTo(HsCorrectiveAction::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
