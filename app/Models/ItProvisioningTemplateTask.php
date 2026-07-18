<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItProvisioningTemplateTask extends Model
{
    use HasFactory;

    public const CATEGORIES = [
        'account', 'group', 'licence', 'email', 'device', 'peripheral', 'network',
        'access_control', 'telephony', 'vehicle_technology', 'healthcare_access', 'equipment', 'other',
    ];

    public const ACTIONS = ['grant', 'change', 'revoke', 'recover', 'configure', 'verify'];

    public const FULFILLER_FIELDS = [
        'employee_number', 'work_email', 'position_title', 'position_role',
        'employment_type', 'primary_site', 'manager',
    ];

    public const TRIGGER_FIELDS = ['position_role', 'primary_site_id', 'employment_type'];

    protected $fillable = [
        'provisioning_template_id',
        'task_key',
        'title',
        'description',
        'category',
        'action',
        'request_type',
        'responsible_team_id',
        'stage',
        'sort_order',
        'dependency_task_keys',
        'trigger_fields',
        'approval_required',
        'evidence_required',
        'due_offset_days',
        'fulfiller_fields',
    ];

    protected $casts = [
        'stage' => 'integer',
        'sort_order' => 'integer',
        'dependency_task_keys' => 'array',
        'trigger_fields' => 'array',
        'approval_required' => 'boolean',
        'evidence_required' => 'boolean',
        'due_offset_days' => 'integer',
        'fulfiller_fields' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ItProvisioningTemplate::class, 'provisioning_template_id');
    }

    public function responsibleTeam(): BelongsTo
    {
        return $this->belongsTo(ItTeam::class, 'responsible_team_id');
    }
}
