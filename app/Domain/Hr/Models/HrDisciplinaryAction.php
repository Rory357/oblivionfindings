<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrDisciplinaryAction extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'case_id',
        'employee_user_id',
        'stage',
        'action_type',
        'allegation_summary',
        'investigation_notes',
        'investigator_user_id',
        'notice_issued_at',
        'notice_document_path',
        'meeting_scheduled_at',
        'meeting_location',
        'support_person_advised',
        'meeting_held_at',
        'meeting_notes',
        'meeting_attendees',
        'employee_response',
        'response_deadline',
        'outcome',
        'outcome_decided_at',
        'outcome_decided_by',
        'outcome_rationale',
        'outcome_communicated_at',
        'outcome_document_path',
        'good_faith_checklist',
        'appeal_received',
        'appeal_received_at',
        'appeal_notes',
        'appeal_outcome',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'notice_issued_at' => 'datetime',
        'meeting_scheduled_at' => 'datetime',
        'support_person_advised' => 'boolean',
        'meeting_held_at' => 'datetime',
        'meeting_attendees' => 'array',
        'response_deadline' => 'datetime',
        'outcome_decided_at' => 'datetime',
        'outcome_communicated_at' => 'datetime',
        'good_faith_checklist' => 'array',
        'appeal_received' => 'boolean',
        'appeal_received_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function hrCase(): BelongsTo
    {
        return $this->belongsTo(HrCase::class, 'case_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_user_id');
    }

    public function outcomeDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outcome_decided_by');
    }
}
