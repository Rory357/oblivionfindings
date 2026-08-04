<?php

namespace App\Models;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItProvisioningWorkflow extends Model
{
    use WritesLegacyStorageContext;

    public const STATUSES = ['pending', 'in_progress', 'partially_failed', 'completed', 'cancelled'];

    protected $fillable = [
        'employee_profile_id',
        'provisioning_template_id',
        'lifecycle_type',
        'source_type',
        'source_id',
        'source_event_key',
        'status',
        'effective_at',
        'role_snapshot',
        'site_id_snapshot',
        'employment_type_snapshot',
        'changes',
        'created_by_user_id',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
        'changes' => 'array',
    ];

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ItProvisioningTemplate::class, 'provisioning_template_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ItProvisioningRequest::class, 'provisioning_workflow_id')
            ->orderBy('stage')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
