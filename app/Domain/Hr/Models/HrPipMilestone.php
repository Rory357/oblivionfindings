<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPipMilestone extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'hr_pip_milestones';

    protected $fillable = [
        'pip_id',
        'title',
        'description',
        'due_date',
        'status',
        'outcome',
        'reviewer_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function pip(): BelongsTo
    {
        return $this->belongsTo(HrPerformanceImprovementPlan::class, 'pip_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
