<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarePlanGoalStep extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'care_plan_goal_id',
        'title',
        'is_complete',
        'sort_order',
        'target_date',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    protected $casts = [
        'is_complete' => 'boolean',
        'target_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function goal()
    {
        return $this->belongsTo(CarePlanGoal::class, 'care_plan_goal_id');
    }
}
