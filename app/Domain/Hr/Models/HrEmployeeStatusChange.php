<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrEmployeeStatusChange extends Model
{
    use WritesLegacyStorageContext;

    public $timestamps = false;

    protected $fillable = [
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
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
