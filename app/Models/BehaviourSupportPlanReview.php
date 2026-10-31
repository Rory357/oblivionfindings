<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviourSupportPlanReview extends Model
{
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'plan_version_id', 'approval_id', 'requested_resulting_status', 'approval_disposition', 'reviewer_snapshot', 'source_version', 'command_key', 'audit_id',
        'behaviour_support_plan_id',
        'reviewed_by',
        'reviewed_at',
        'outcome',
        'next_review_date',
        'resulting_status',
        'notes',
    ];

    protected $casts = [
        'plan_version_id' => 'integer', 'approval_id' => 'integer', 'reviewer_snapshot' => 'array', 'source_version' => 'integer',
        'reviewed_at' => 'datetime',
        'next_review_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(function ($row): void {
            if ($row->command_key !== null) {
                throw new \LogicException('A recorded plan review cannot be rewritten.');
            }
        });
        static::deleting(function ($row): void {
            if ($row->command_key !== null) {
                throw new \LogicException('A recorded plan review cannot be erased.');
            }
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlanVersion::class, 'plan_version_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlanApproval::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlan::class, 'behaviour_support_plan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
