<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Timesheet extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $appends = [
        'is_snapshot_complete',
        'is_payroll_segment_complete',
        'is_protected_from_changes',
    ];

    protected $fillable = [
        'user_id',
        'client_id',
        'shift_id',
        'activity_type',
        'activity_items',
        'shift_site_id',
        'shift_service_context_id',
        'site_id',
        'attendance_session_id',
        'work_date',
        'starts_at',
        'ends_at',
        'break_minutes',
        'mileage_km',
        'sleepover',
        'on_call',
        'allowance_notes',
        'public_holiday',
        'notes',
        'is_residential_billable',
        'shift_site_name_snapshot',
        'shift_location_snapshot',
        'service_context_name_snapshot',
        'client_name_snapshot',
        'staff_name_snapshot',
        'shift_type_snapshot',
        'coverage_roles_snapshot',
        'status',
        'submitted_at',
        'submitted_by',
        'created_by',
        'approved_by',
        'approved_at',
        'decision_notes',
        'returned_at',
        'returned_by',
        'returned_notes',
        'hr_time_entry_id',
        'pay_rate',
        'pay_type',
        'exported_to_payroll_at',
        'payroll_reference',
        'payroll_segments_exported',
        'reconciliation_status',
        'reconciliation_severity',
        'reconciliation_detected_at',
        'reconciliation_summary',
        'reconciliation_findings',
        'mileage_journal_id',
        'archived_at',
        'archived_reason',
    ];

    protected $casts = [
        'work_date' => 'date',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'shift_site_id' => 'integer',
        'shift_service_context_id' => 'integer',
        'site_id' => 'integer',
        'activity_items' => 'array',
        'archived_at' => 'datetime',
        'mileage_km' => 'decimal:2',
        'sleepover' => 'boolean',
        'on_call' => 'boolean',
        'public_holiday' => 'boolean',
        'coverage_roles_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'returned_at' => 'datetime',
        'is_residential_billable' => 'boolean',
        'pay_rate' => 'decimal:2',
        'exported_to_payroll_at' => 'datetime',
        'payroll_segments_exported' => 'array',
        'reconciliation_detected_at' => 'datetime',
        'reconciliation_findings' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $timesheet): void {
            app(\App\Services\ShiftSafetyInvariantService::class)->assertTimesheet($timesheet);
        });

        static::creating(function (self $timesheet): void {
            $timesheet->ensureUniqueShiftUserPair();
            $timesheet->ensureWorkflowAllowedForShift();
        });

        static::updating(function (self $timesheet): void {
            if ($timesheet->isDirty(['shift_id', 'user_id'])) {
                $timesheet->ensureUniqueShiftUserPair();
            }

            if ($timesheet->isDirty('status')) {
                $timesheet->ensureWorkflowAllowedForShift();
            }

            $lockedOperationalFields = [
                'user_id',
                'client_id',
                'shift_id',
                'shift_site_id',
                'shift_service_context_id',
                'work_date',
                'starts_at',
                'ends_at',
                'break_minutes',
                'mileage_km',
                'sleepover',
                'on_call',
                'allowance_notes',
                'public_holiday',
                'notes',
                'is_residential_billable',
                'shift_site_name_snapshot',
                'shift_location_snapshot',
                'service_context_name_snapshot',
                'client_name_snapshot',
                'staff_name_snapshot',
                'shift_type_snapshot',
                'coverage_roles_snapshot',
                'pay_rate',
                'pay_type',
            ];

            $wasApproved = $timesheet->getOriginal('status') === 'approved';
            $wasPayrollLinked = ! empty($timesheet->getOriginal('payroll_reference'))
                || ! empty($timesheet->getOriginal('exported_to_payroll_at'));
            $lockedWorkflowFields = [
                'status',
                'submitted_at',
                'submitted_by',
                'approved_at',
                'approved_by',
                'decision_notes',
                'returned_at',
                'returned_by',
                'returned_notes',
            ];

            if (($wasApproved || $wasPayrollLinked) && $timesheet->isDirty($lockedOperationalFields)) {
                throw new \LogicException('Approved or payroll-linked timesheets are immutable. Use a controlled correction workflow instead of editing the original record.');
            }

            if ($wasPayrollLinked && $timesheet->isDirty($lockedWorkflowFields)) {
                throw new \LogicException('Payroll-linked timesheets cannot change workflow state after export confirmation.');
            }
        });
    }

    public static function ensureNoDuplicateShiftUserPair(?int $shiftId, ?int $userId, ?int $ignoreTimesheetId = null): void
    {
        if (! $shiftId || ! $userId) {
            return;
        }

        $query = static::query()
            ->where('shift_id', $shiftId)
            ->where('user_id', $userId);

        if ($ignoreTimesheetId) {
            $query->whereKeyNot($ignoreTimesheetId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'shift_id' => 'A timesheet already exists for this shift and staff member.',
            ]);
        }
    }

    public function ensureUniqueShiftUserPair(): void
    {
        static::ensureNoDuplicateShiftUserPair(
            $this->shift_id ? (int) $this->shift_id : null,
            $this->user_id ? (int) $this->user_id : null,
            $this->exists ? (int) $this->getKey() : null,
        );
    }

    public function ensureWorkflowAllowedForShift(): void
    {
        if (! in_array((string) $this->status, ['submitted', 'approved'], true)) {
            return;
        }

        if ($this->linkedShiftIsCancelled()) {
            throw ValidationException::withMessages([
                'shift_id' => 'Timesheets linked to cancelled shifts cannot be submitted or approved.',
            ]);
        }

        app(\App\Services\Operations\TimesheetReconciliationService::class)
            ->assertWorkflowAllowed($this, (string) $this->status, false);
    }

    public function linkedShiftIsCancelled(): bool
    {
        if (! $this->shift_id) {
            return false;
        }

        $status = $this->relationLoaded('shift')
            ? $this->shift?->status
            : Shift::query()->whereKey($this->shift_id)->value('status');

        return $status === 'cancelled';
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Per-client time allocations against this timesheet. When empty, the
     * timesheet behaves as a single-client record using {@see self::client()}
     * — `effectiveClientAllocations()` returns the synthesised single-row
     *  representation so reads have a uniform shape.
     */
    public function clientAllocations()
    {
        return $this->hasMany(TimesheetClientAllocation::class, 'timesheet_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Materialised allocation rows for downstream consumers. If the database
     * has explicit rows we return them; otherwise we synthesise one row from
     * the timesheet's primary client + total hours so legacy data keeps the
     * same shape without a destructive backfill.
     *
     * @return \Illuminate\Support\Collection<int, array{client_id:int,hours:float,allocation_method:string,starts_at:?string,ends_at:?string,notes:?string,sort_order:int}>
     */
    public function effectiveClientAllocations(): \Illuminate\Support\Collection
    {
        if ($this->relationLoaded('clientAllocations') && $this->clientAllocations->isNotEmpty()) {
            return $this->clientAllocations->map(fn (TimesheetClientAllocation $a) => [
                'id' => $a->id,
                'client_id' => $a->client_id,
                'hours' => (float) $a->hours,
                'allocation_method' => $a->allocation_method,
                'starts_at' => $a->starts_at?->toIso8601String(),
                'ends_at' => $a->ends_at?->toIso8601String(),
                'notes' => $a->notes,
                'sort_order' => $a->sort_order,
            ])->values();
        }

        $rows = $this->clientAllocations()->get();
        if ($rows->isNotEmpty()) {
            return $rows->map(fn (TimesheetClientAllocation $a) => [
                'id' => $a->id,
                'client_id' => $a->client_id,
                'hours' => (float) $a->hours,
                'allocation_method' => $a->allocation_method,
                'starts_at' => $a->starts_at?->toIso8601String(),
                'ends_at' => $a->ends_at?->toIso8601String(),
                'notes' => $a->notes,
                'sort_order' => $a->sort_order,
            ])->values();
        }

        // Synthesise a single-row allocation from the timesheet's primary
        // client. Existing data never wrote allocation rows, so this is the
        // safe default until the worker explicitly chooses a different
        // method through the review popup.
        //
        // The total comes from the `total_hours` accessor (starts_at - ends_at
        // - break_minutes); there's no raw `hours` column.
        return collect([
            [
                'id' => null,
                'client_id' => (int) $this->client_id,
                'hours' => (float) $this->total_hours,
                'allocation_method' => TimesheetClientAllocation::METHOD_SINGLE,
                'starts_at' => null,
                'ends_at' => null,
                'notes' => null,
                'sort_order' => 0,
            ],
        ]);
    }

    /**
     * Inferred allocation method for the whole timesheet. Picks the first
     * row's method when allocations exist; otherwise reports SINGLE.
     */
    public function dominantAllocationMethod(): string
    {
        $first = $this->effectiveClientAllocations()->first();

        return $first['allocation_method'] ?? TimesheetClientAllocation::METHOD_SINGLE;
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Alias kept for compatibility with code/tests that eager load `user`.
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function attendanceSession()
    {
        return $this->belongsTo(\App\Domain\Hr\Models\HrAttendanceSession::class, 'attendance_session_id');
    }

    public function mileageJournal()
    {
        return $this->belongsTo(\App\Domain\Finance\Models\FinJournal::class, 'mileage_journal_id');
    }

    public function shiftSite()
    {
        return $this->belongsTo(Site::class, 'shift_site_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function shiftServiceContext()
    {
        return $this->belongsTo(ServiceContext::class, 'shift_service_context_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function returner()
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amendments()
    {
        return $this->hasMany(TimesheetAmendment::class);
    }

    public function pendingAmendment()
    {
        return $this->hasOne(TimesheetAmendment::class)->where('status', TimesheetAmendment::STATUS_PENDING);
    }

    public function getTotalHoursAttribute(): float
    {
        if (! $this->starts_at || ! $this->ends_at) {
            return 0.0;
        }

        $minutes = $this->starts_at->diffInMinutes($this->ends_at) - (int) $this->break_minutes;
        return round(max($minutes, 0) / 60, 2);
    }

    public function getTotalMinutesAttribute(): int
    {
        if (! $this->starts_at || ! $this->ends_at) {
            return 0;
        }

        return max(0, (int) $this->starts_at->diffInMinutes($this->ends_at) - (int) $this->break_minutes);
    }

    public function getIsSnapshotCompleteAttribute(): bool
    {
        return filled($this->client_name_snapshot)
            && filled($this->staff_name_snapshot)
            && filled($this->shift_type_snapshot);
    }

    public function getIsPayrollSegmentCompleteAttribute(): bool
    {
        if (! $this->starts_at || ! $this->ends_at) {
            return false;
        }

        if ($this->exported_to_payroll_at) {
            return true;
        }

        $totalMinutes = (int) $this->starts_at->diffInMinutes($this->ends_at);
        $confirmedMinutes = (int) collect($this->payroll_segments_exported ?? [])
            ->sum(fn (array $segment) => (int) ($segment['segment_minutes'] ?? 0));

        return $totalMinutes > 0 && $confirmedMinutes >= $totalMinutes;
    }

    public function getIsProtectedFromChangesAttribute(): bool
    {
        return $this->status === 'approved'
            || filled($this->payroll_reference)
            || filled($this->exported_to_payroll_at);
    }

    public function scopeReconciliationBlocked($query)
    {
        return $query->where('reconciliation_status', 'blocked');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeReconciliationNeedsReview($query)
    {
        return $query->whereIn('reconciliation_status', ['review', 'blocked']);
    }
}
