<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkplaceInjury extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'site_id',
        'related_incident_id',
        'injury_date',
        'injury_type',
        'body_part_affected',
        'severity',
        'description',
        'immediate_treatment',
        'medical_treatment_type',
        'worksafe_notifiable',
        'acc_claim_lodged',
        'acc_claim_number',
        'lost_time_days',
        'expected_return_date',
        'actual_return_date',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'injury_date' => 'datetime',
        'worksafe_notifiable' => 'boolean',
        'acc_claim_lodged' => 'boolean',
        'lost_time_days' => 'integer',
        'expected_return_date' => 'date',
        'actual_return_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function relatedIncident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'related_incident_id');
    }

    public function returnToWorkPlans(): HasMany
    {
        return $this->hasMany(ReturnToWorkPlan::class);
    }

    public function capacityAssessments(): HasMany
    {
        return $this->hasMany(WorkCapacityAssessment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkplaceInjuryAttachment::class);
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

    public function scopeWorksafeNotifiable($query)
    {
        return $query->where('worksafe_notifiable', true);
    }

    public function scopeWithLostTime($query)
    {
        return $query->where('lost_time_days', '>', 0);
    }

    public function scopeOfSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }
}
