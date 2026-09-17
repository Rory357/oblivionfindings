<?php

namespace App\Models;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ItProvisioningWorkflow extends Model
{
    use WritesLegacyStorageContext;

    public const STATUSES = ['pending', 'in_progress', 'partially_failed', 'completed', 'cancelled'];

    protected $fillable = [
        'lock_version', 'owner_user_id', 'cover_user_id', 'original_effective_at', 'cancelled_at', 'cancellation_reason',
        'employee_profile_id',
        'provisioning_template_id',
        'template_version_id',
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
        'lock_version' => 'integer',
        'original_effective_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'effective_at' => 'datetime',
        'changes' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $workflow): void {
            if (array_key_exists('lock_version', $workflow->getAttributes()) && $workflow->isDirty()) {
                $workflow->lock_version = ((int) $workflow->getOriginal('lock_version')) + 1;
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function events(): MorphMany
    {
        return $this->morphMany(ItTicketEvent::class, 'subject');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cover_user_id');
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(ItProvisioningTemplateVersion::class, 'template_version_id');
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
