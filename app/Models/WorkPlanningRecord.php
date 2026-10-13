<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Team-maintained plans, never evidence of an automatic safety or compliance check. */
final class WorkPlanningRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'due_date' => 'date', 'completed_at' => 'datetime', 'progress' => 'integer', 'version' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
