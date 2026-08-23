<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\StaffTimeOff;
use App\Models\User;
use Database\Factories\Hr\HrLeaveRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrLeaveRequest extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrLeaveRequestFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'user_id',
        'leave_type',
        'period',
        'starts_at',
        'ends_at',
        'hours_requested',
        'reason',
        'supporting_doc_path',
        'status',
        'submitted_at',
        'approval_due_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'escalated_to',
        'escalation_level',
        'escalated_at',
        'time_off_id',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'hours_requested' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approval_due_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'escalation_level' => 'integer',
        'escalated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            if (! $request->isDirty([
                'user_id',
                'leave_type',
                'starts_at',
                'ends_at',
                'hours_requested',
                'status',
            ])) {
                return;
            }

            if (HrPayrollSourceUse::query()
                ->where('leave_request_id', $request->getKey())
                ->whereNotNull('active_source_identity')
                ->exists()) {
                throw new \LogicException(
                    'This leave request is claimed by an active payroll run and cannot be changed.',
                );
            }
        });

        static::deleting(function (self $request): void {
            if (HrPayrollSourceUse::query()
                ->where('leave_request_id', $request->getKey())
                ->whereNotNull('active_source_identity')
                ->exists()) {
                throw new \LogicException('Payroll-claimed leave evidence cannot be deleted.');
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to');
    }

    /**
     * The roster-side projection of this request once approved.
     */
    public function timeOff(): BelongsTo
    {
        return $this->belongsTo(StaffTimeOff::class, 'time_off_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
