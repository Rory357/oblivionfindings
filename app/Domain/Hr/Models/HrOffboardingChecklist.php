<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrOffboardingChecklist extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $table = 'hr_offboarding_checklists';

    protected $fillable = [
        'tenant_id',
        'employee_profile_id',
        'template_key',
        'status',
        'started_at',
        'completed_at',
        'due_date',
        'previous_employee_end_date',
        'created_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'due_date' => 'date',
        'previous_employee_end_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(HrOffboardingTask::class, 'offboarding_checklist_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
