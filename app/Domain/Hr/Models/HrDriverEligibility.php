<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrDriverEligibility extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'hr_driver_eligibility';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'licence_number',
        'licence_class',
        'licence_endorsements',
        'licence_expires_at',
        'licence_document_path',
        'can_drive_clients',
        'can_drive_clients_approved_by',
        'can_drive_clients_approved_at',
        'incident_free_since',
        'last_reviewed_at',
        'next_review_at',
        'status',
        'suspension_reason',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'licence_endorsements' => 'array',
        'licence_expires_at' => 'date',
        'can_drive_clients' => 'boolean',
        'can_drive_clients_approved_at' => 'datetime',
        'incident_free_since' => 'date',
        'last_reviewed_at' => 'datetime',
        'next_review_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'can_drive_clients_approved_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('status', 'eligible');
    }

    public function scopeExpiring(Builder $query, int $days = 30): Builder
    {
        return $query->where('licence_expires_at', '<=', now()->addDays($days))
            ->where('licence_expires_at', '>=', now());
    }
}
