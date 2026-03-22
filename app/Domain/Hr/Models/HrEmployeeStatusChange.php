<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrEmployeeStatusChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'employee_profile_id',
        'previous_status',
        'new_status',
        'reason',
        'effective_date',
        'changed_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'created_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
