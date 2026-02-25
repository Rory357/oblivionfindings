<?php

namespace App\Domain\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\AuditableChanges;

class HrComplianceRequirement extends Model
{
    use AuditableChanges;

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
        'hard_stop' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function matrixRules(): HasMany
    {
        return $this->hasMany(HrComplianceMatrix::class, 'requirement_id');
    }

    public function staffStatuses(): HasMany
    {
        return $this->hasMany(HrStaffComplianceStatus::class, 'requirement_id');
    }
}