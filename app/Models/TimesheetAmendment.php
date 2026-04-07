<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimesheetAmendment extends Model
{
    use AuditableChanges;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const AMENDABLE_FIELDS = [
        'starts_at',
        'ends_at',
        'break_minutes',
        'mileage_km',
        'sleepover',
        'on_call',
        'public_holiday',
        'notes',
        'pay_rate',
        'pay_type',
    ];

    protected $fillable = [
        'timesheet_id',
        'status',
        'original_values',
        'proposed_values',
        'reason',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'payroll_adjustment_required',
        'applied_at',
    ];

    protected $casts = [
        'original_values' => 'array',
        'proposed_values' => 'array',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
        'payroll_adjustment_required' => 'boolean',
    ];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
