<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCompensationHistory extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $table = 'hr_compensation_history';

    protected $fillable = [
        'tenant_id',
        'employee_profile_id',
        'change_type',
        'previous_hourly_rate',
        'new_hourly_rate',
        'previous_annual_salary',
        'new_annual_salary',
        'change_percentage',
        'reason',
        'effective_date',
        'approved_by',
        'created_by',
    ];

    protected $casts = [
        'previous_hourly_rate' => 'encrypted',
        'new_hourly_rate' => 'encrypted',
        'previous_annual_salary' => 'encrypted',
        'new_annual_salary' => 'encrypted',
        'change_percentage' => 'decimal:2',
        'effective_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

}
