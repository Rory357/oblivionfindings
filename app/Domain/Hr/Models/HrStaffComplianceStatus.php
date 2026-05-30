<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrStaffComplianceStatus extends Model
{
    use HasFactory, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\HrStaffComplianceStatusFactory::new();
    }

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

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(HrComplianceRequirement::class, 'requirement_id');
    }

    public function exemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exempted_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '>=', now())
            ->where('expires_at', '<=', now()->addDays($days));
    }

    public function scopeNonCompliant($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'non_compliant')
              ->orWhere(function ($q2) {
                  $q2->whereNotNull('expires_at')
                     ->where('expires_at', '<', now());
              });
        });
    }
}
