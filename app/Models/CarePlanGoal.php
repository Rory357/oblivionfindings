<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarePlanGoal extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'care_plan_id',
        'client_id',
        'title',
        'description',
        'category',
        'target_date',
        'status',
        'priority',
        'progress_percentage',
        'outcome_notes',
        'created_by',
    ];

    protected $casts = [
        'target_date' => 'date',
    ];

    public function carePlan()
    {
        return $this->belongsTo(CarePlan::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function progressNotes()
    {
        return $this->hasMany(ProgressNote::class, 'care_plan_goal_id');
    }
}
