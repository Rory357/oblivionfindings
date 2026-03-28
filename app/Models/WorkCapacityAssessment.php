<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkCapacityAssessment extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'workplace_injury_id',
        'user_id',
        'assessment_date',
        'assessor_name',
        'assessor_type',
        'capacity_status',
        'assessment_summary',
        'restrictions',
        'recommendations',
        'next_assessment_date',
        'document_path',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'assessment_date' => 'date',
        'next_assessment_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function workplaceInjury(): BelongsTo
    {
        return $this->belongsTo(WorkplaceInjury::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function scopeForWorker($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeFitForFullDuties($query)
    {
        return $query->where('capacity_status', 'fit_for_full_duties');
    }

    public function scopeRequiringReview($query)
    {
        return $query->where('capacity_status', 'requires_review');
    }

    public function scopeNeedingFollowUp($query)
    {
        return $query->whereNotNull('next_assessment_date')
            ->where('next_assessment_date', '<=', now());
    }
}
