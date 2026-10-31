<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class BehaviourSupportPlanVersion extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['sequence' => 'integer', 'client_id' => 'integer', 'site_id' => 'integer', 'stay_id' => 'integer', 'candidate_version_id' => 'integer', 'approval_id' => 'integer', 'content' => 'array', 'actor_snapshot' => 'array', 'policy_snapshot' => 'array', 'recorded_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime', 'review_date' => 'date'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Immutable plan evidence cannot be rewritten.'));
        self::deleting(fn () => throw new LogicException('Immutable plan evidence cannot be deleted.'));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlan::class, 'behaviour_support_plan_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlanApproval::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'candidate_version_id');
    }
}
