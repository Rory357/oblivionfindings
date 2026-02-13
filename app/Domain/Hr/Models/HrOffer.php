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
        'proposed_start_date',
        'employment_type',
        'hours_per_week',
        'hourly_rate',
        'annual_salary',
        'primary_site_id',
        'conditions',
        'approval_status',
        'approved_by',
        'approved_at',
        'sent_at',
        'response',
        'response_at',
        'response_notes',
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
        'sent_at' => 'datetime',
        'response_at' => 'datetime',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrDocumentTemplate::class, 'template_id');
    }
}
