<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProgressNote extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'client_id',
        'shift_id',
        'care_plan_goal_id',
        'author_id',
        'note_type',
        'content',
        'mood_rating',
        'is_flagged',
        'flagged_reason',
        'ai_summary',
        'visibility',
    ];

    protected $casts = [
        'is_flagged' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function goal()
    {
        return $this->belongsTo(CarePlanGoal::class, 'care_plan_goal_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeFlagged($query)
    {
        return $query->where('is_flagged', true);
    }

    public function scopeForFamily($query)
    {
        return $query->where('visibility', 'include_family');
    }
}
