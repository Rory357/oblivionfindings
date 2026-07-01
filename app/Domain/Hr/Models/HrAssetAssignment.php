<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrAssetAssignment extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'employee_profile_id',
        'assigned_at',
        'returned_at',
        'due_at',
        'acknowledged_at',
        'signature_id',
        'condition_on_assign',
        'condition_on_return',
        'photos',
        'assigned_by',
        'notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'returned_at' => 'datetime',
        'due_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'photos' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function asset(): BelongsTo
    {
        return $this->belongsTo(HrAsset::class, 'asset_id');
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(HrDocumentSignature::class, 'signature_id');
    }

    /** Open assignment whose return-by date has passed. */
    public function isOverdue(): bool
    {
        return $this->returned_at === null
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('returned_at');
    }
}
