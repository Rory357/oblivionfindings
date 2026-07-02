<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrOffer extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'application_id',
        'template_id',
        'position_title',
        'position_role',
        'position_id',
        'proposed_start_date',
        'employment_type',
        'hours_per_week',
        'hourly_rate',
        'annual_salary',
        'primary_site_id',
        'conditions',
        'offer_letter_path',
        'offer_letter_name',
        'approval_status',
        'approved_by',
        'approved_at',
        'approval_requested_at',
        'approval_declined_reason',
        'approval_reminder_sent_at',
        'sent_at',
        'candidate_portal_token',
        'portal_expires_at',
        'expiry_reminder_sent_at',
        'expired_notice_sent_at',
        'response',
        'response_at',
        'response_notes',
        'signed_full_name',
        'signed_at',
        'signed_ip',
        'work_email_provisioned',
        'work_email',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'proposed_start_date' => 'date',
        'hours_per_week' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'annual_salary' => 'decimal:2',
        'approved_at' => 'datetime',
        'approval_requested_at' => 'datetime',
        'approval_reminder_sent_at' => 'datetime',
        'sent_at' => 'datetime',
        'portal_expires_at' => 'datetime',
        'expiry_reminder_sent_at' => 'datetime',
        'expired_notice_sent_at' => 'datetime',
        'response_at' => 'datetime',
        'signed_at' => 'datetime',
        'work_email_provisioned' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function application(): BelongsTo
    {
        return $this->belongsTo(HrApplication::class, 'application_id');
    }

    public function primarySite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'primary_site_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrDocumentTemplate::class, 'template_id');
    }
}
