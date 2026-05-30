<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrComplianceRequirement extends Model
{
    use HasFactory, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\HrComplianceRequirementFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'category',
        'check_type',
        'reference_id',
        'validity_months',
        'renewal_reminder_days',
        'hard_stop',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'validity_months' => 'integer',
        'renewal_reminder_days' => 'integer',
        'hard_stop' => 'boolean',
        'is_active' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function matrixEntries(): HasMany
    {
        return $this->hasMany(HrComplianceMatrix::class, 'requirement_id');
    }

    public function staffStatuses(): HasMany
    {
        return $this->hasMany(HrStaffComplianceStatus::class, 'requirement_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeHardStop($query)
    {
        return $query->where('hard_stop', true);
    }
}
