<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReturnToWorkPlan extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'workplace_injury_id',
        'worker_id',
        'manager_id',
        'plan_start_date',
        'plan_end_date',
        'status',
        'medical_clearance_notes',
        'medical_clearance_date',
        'medical_clearance_provider',
        'goals',
        'stages',
        'workplace_modifications',
        'worker_agreement_notes',
        'worker_agreed',
        'worker_agreed_at',
        'next_review_date',
        'review_notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'plan_start_date' => 'date',
        'plan_end_date' => 'date',
        'medical_clearance_date' => 'date',
        'goals' => 'array',
        'stages' => 'array',
        'worker_agreed' => 'boolean',
        'worker_agreed_at' => 'date',
        'next_review_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function workplaceInjury(): BelongsTo
    {
        return $this->belongsTo(WorkplaceInjury::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function modifiedDuties(): HasMany
    {
        return $this->hasMany(ModifiedDuty::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeNeedingReview($query)
    {
        return $query->whereNotNull('next_review_date')
            ->where('next_review_date', '<=', now())
            ->where('status', 'active');
    }
}
