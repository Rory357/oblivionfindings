<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class BehaviourSupportPlanApproval extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['plan_version_id' => 'integer', 'client_id' => 'integer', 'site_id' => 'integer', 'stay_id' => 'integer', 'approver_id' => 'integer', 'recorded_by' => 'integer', 'supersedes_approval_id' => 'integer', 'approver_snapshot' => 'array', 'recorder_snapshot' => 'array', 'evidence_references' => 'array', 'policy_snapshot' => 'array', 'approved_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime', 'effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Immutable plan evidence cannot be rewritten.'));
        self::deleting(fn () => throw new LogicException('Immutable plan evidence cannot be deleted.'));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlan::class, 'behaviour_support_plan_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlanVersion::class, 'plan_version_id');
    }

    public function settingChange(): BelongsTo
    {
        return $this->belongsTo(HealthSafetySettingChange::class, 'setting_change_id');
    }
}
