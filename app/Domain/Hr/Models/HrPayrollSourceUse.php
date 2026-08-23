<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HrPayrollSourceUse extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'payroll_run_id',
        'payroll_run_item_id',
        'source_type',
        'timesheet_id',
        'leave_request_id',
        'user_id',
        'employee_profile_id',
        'site_id',
        'source_date',
        'hours',
        'hourly_rate',
        'amount',
        'source_identity',
        'active_source_identity',
        'source_payload_sha256',
        'released_at',
        'released_by',
        'release_reason',
    ];

    protected $casts = [
        'source_date' => 'date',
        'hours' => 'decimal:4',
        'hourly_rate' => 'decimal:4',
        'amount' => 'decimal:2',
        'released_at' => 'datetime',
        'released_by' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $use): void {
            $isTimesheet = $use->source_type === 'timesheet'
                && $use->timesheet_id !== null
                && $use->leave_request_id === null;
            $isLeave = $use->source_type === 'leave'
                && $use->leave_request_id !== null
                && $use->timesheet_id === null;

            if (! $isTimesheet && ! $isLeave) {
                throw new LogicException('Payroll source evidence must identify exactly one canonical source.');
            }

            if ($use->active_source_identity !== $use->source_identity) {
                throw new LogicException('New payroll source evidence must begin as an active claim.');
            }

            if ($use->employee_profile_id === null
                || $use->released_at !== null
                || $use->released_by !== null
                || filled($use->release_reason)) {
                throw new LogicException('New payroll source evidence cannot begin released or without employee provenance.');
            }
        });

        static::updating(function (self $use): void {
            $allowed = ['active_source_identity', 'released_at', 'released_by', 'release_reason', 'updated_at'];
            $unexpected = array_diff(array_keys($use->getDirty()), $allowed);

            if ($unexpected !== []) {
                throw new LogicException('Payroll source evidence is immutable; only a controlled release is permitted.');
            }

            $wasActive = $use->getOriginal('active_source_identity') === $use->source_identity
                && $use->getOriginal('released_at') === null
                && $use->getOriginal('released_by') === null
                && blank($use->getOriginal('release_reason'));
            $allReleaseFieldsChanged = $use->isDirty('active_source_identity')
                && $use->isDirty('released_at')
                && $use->isDirty('released_by')
                && $use->isDirty('release_reason');

            if (! $wasActive
                || ! $allReleaseFieldsChanged
                || $use->active_source_identity !== null
                || $use->released_at === null
                || $use->released_by === null
                || blank($use->release_reason)) {
                throw new LogicException('Payroll source evidence can only be released with complete correction provenance.');
            }
        });

        static::deleting(fn () => throw new LogicException('Payroll source evidence cannot be deleted.'));
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(HrPayrollRun::class);
    }

    public function payrollRunItem(): BelongsTo
    {
        return $this->belongsTo(HrPayrollRunItem::class);
    }

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(HrLeaveRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
