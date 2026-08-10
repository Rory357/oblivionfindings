<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use App\Traits\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class HrStaffComplianceStatus extends Model
{
    use AuditableChanges;

    protected $table = 'hr_staff_compliance_status';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'requirement_id',
        'status',
        'evidence_type',
        'evidence_id',
        'valid_from',
        'expires_at',
        'exemption_reason',
        'exempted_by',
        'last_checked_at',
        'next_check_at',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'expires_at' => 'date',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(HrComplianceRequirement::class);
    }

    public function evidence(): MorphTo
    {
        return $this->morphTo();
    }

    public function exemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exempted_by');
    }
}