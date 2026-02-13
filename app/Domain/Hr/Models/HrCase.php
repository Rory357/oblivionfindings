<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrCase extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'case_number',
        'user_id',
        'case_type',
        'severity',
        'status',
        'title',
        'description',
        'reported_by',
        'assigned_to',
        'opened_at',
        'closed_at',
        'outcome',
        'outcome_type',
        'is_confidential',
        'access_list',
        'linked_incident_ids',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'is_confidential' => 'boolean',
        'access_list' => 'array',
        'linked_incident_ids' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events(): HasMany
    {
        return $this->hasMany(HrCaseEvent::class, 'case_id');
    }

    public function disciplinaryActions(): HasMany
    {
        return $this->hasMany(HrDisciplinaryAction::class, 'case_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeConfidential(Builder $query): Builder
    {
        return $query->where('is_confidential', true);
    }
}
