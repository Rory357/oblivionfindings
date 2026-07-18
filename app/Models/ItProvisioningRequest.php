<?php

namespace App\Models;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An onboarding-driven IT work item (account / access / equipment / other)
 * for a new hire, worked from the /it queue. Optionally linked to the source
 * HrOnboardingTask so fulfilment auto-completes the checklist task.
 */
class ItProvisioningRequest extends Model
{
    use AuditableChanges;

    public const TYPES = ['account', 'access', 'equipment', 'other'];

    public const STATUSES = ['pending', 'in_progress', 'failed', 'done', 'cancelled'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = [
        'tenant_id',
        'employee_profile_id',
        'provisioning_workflow_id',
        'provisioning_template_task_id',
        'onboarding_task_id',
        'offboarding_task_id',
        'type',
        'task_key',
        'action',
        'category',
        'item',
        'assigned_to_user_id',
        'responsible_team_id',
        'stage',
        'dependency_request_ids',
        'approval_required',
        'approval_status',
        'approved_by_user_id',
        'approved_at',
        'evidence_required',
        'evidence_summary',
        'failure_reason',
        'failed_at',
        'fulfiller_context',
        'canonical_target_type',
        'canonical_target_id',
        'status',
        'priority',
        'due_date',
        'external_ref',
        'fulfilled_at',
        'fulfilled_by',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'fulfilled_at' => 'datetime',
        'due_date' => 'date',
        'stage' => 'integer',
        'dependency_request_ids' => 'array',
        'approval_required' => 'boolean',
        'approved_at' => 'datetime',
        'evidence_required' => 'boolean',
        'failed_at' => 'datetime',
        'fulfiller_context' => 'array',
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

    public function offboardingTask(): BelongsTo
    {
        return $this->belongsTo(HrOffboardingTask::class, 'offboarding_task_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ItProvisioningWorkflow::class, 'provisioning_workflow_id');
    }

    public function templateTask(): BelongsTo
    {
        return $this->belongsTo(ItProvisioningTemplateTask::class, 'provisioning_template_task_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function responsibleTeam(): BelongsTo
    {
        return $this->belongsTo(ItTeam::class, 'responsible_team_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Shared IT activity trail (same table as ticket events). */
    public function events(): MorphMany
    {
        return $this->morphMany(ItTicketEvent::class, 'subject');
    }

    /** Helpdesk tickets raised from this request (broken laptop etc.). */
    public function linkedTickets(): HasMany
    {
        return $this->hasMany(ItTicket::class, 'provisioning_request_id');
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
