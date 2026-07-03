<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\HasReferenceNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BehaviourSupportPlan extends Model
{
    use AuditableChanges, HasFactory, HasReferenceNumber, SoftDeletes;

    public const REFERENCE_PREFIX = 'BSP';

    protected $fillable = [
        'reference_number',
        'client_id',
        'title',
        'triggers',
        'de_escalation_strategies',
        'approved_interventions',
        'prohibited_interventions',
        'restrictive_practice_type',
        'developed_by',
        'developed_at',
        'review_date',
        'status',
        'status_changed_at',
        'status_changed_by',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'developed_at' => 'date',
        'review_date' => 'date',
        'status_changed_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function developedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'developed_by');
    }

    public function restraintEvents(): HasMany
    {
        return $this->hasMany(RestraintEvent::class, 'behaviour_support_plan_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(BehaviourSupportPlanReview::class, 'behaviour_support_plan_id');
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
