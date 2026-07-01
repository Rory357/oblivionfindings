<?php

namespace App\Models;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An onboarding-driven IT work item (account / access / equipment / other)
 * for a new hire, worked from the /it queue. Optionally linked to the source
 * HrOnboardingTask so fulfilment auto-completes the checklist task.
 */
class ItProvisioningRequest extends Model
{
    use AuditableChanges;

    public const TYPES = ['account', 'access', 'equipment', 'other'];

    public const STATUSES = ['pending', 'in_progress', 'done', 'cancelled'];

    protected $fillable = [
        'tenant_id',
        'employee_profile_id',
        'onboarding_task_id',
        'type',
        'item',
        'assigned_to_user_id',
        'status',
        'external_ref',
        'fulfilled_at',
        'fulfilled_by',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'fulfilled_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function onboardingTask(): BelongsTo
    {
        return $this->belongsTo(HrOnboardingTask::class, 'onboarding_task_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Classify an onboarding IT task title into a request type. Equipment
     * tasks keep their asset-issue fulfilment path (provisionAssetForTask),
     * so the onboarding bridge skips that type.
     */
    public static function inferTypeFromTitle(string $title): string
    {
        $t = mb_strtolower($title);

        if (preg_match('/laptop|phone|tablet|device|equipment|monitor|headset|hardware|printer|sim\b/', $t)) {
            return 'equipment';
        }

        if (preg_match('/account|login|log-in|email|mailbox|m365|microsoft|licence|license|xero|payroll portal/', $t)) {
            return 'account';
        }

        if (preg_match('/access|fob|badge|door|vpn|swipe|key\b|keys\b|permission/', $t)) {
            return 'access';
        }

        return 'other';
    }
}
