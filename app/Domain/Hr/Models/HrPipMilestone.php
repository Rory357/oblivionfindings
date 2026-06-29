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
        'sort_order',
        'outcome',
        'evidence',
        'evidence_path',
        'reviewer_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'reviewed_at' => 'datetime',
        'sort_order' => 'integer',
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
