<?php

namespace App\Domain\Hr\Models;

use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrTimeEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'shift_id',
        'attendance_session_id',
        'site_id',
        'client_id',
        'entry_date',
        'clock_in',
        'clock_out',
        'break_minutes',
        'total_hours',
        'entry_type',
        'status',
        'notes',
        'project_code',
        'cost_centre',
        'source_type',
        'source_id',
        'pay_type',
        'is_sleepover',
        'is_on_call',
        'is_public_holiday',
        'mileage_km',
        'break_compliance_met',
        'hr_timesheet_id',
        'approved_by',
        'approved_at',
        'amended_by',
        'amended_at',
        'amendment_reason',
        'original_values',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
        'break_minutes' => 'integer',
        'total_hours' => 'decimal:2',
        'mileage_km' => 'decimal:2',
        'is_sleepover' => 'boolean',
        'is_on_call' => 'boolean',
        'is_public_holiday' => 'boolean',
        'break_compliance_met' => 'boolean',
        'approved_at' => 'datetime',
        'amended_at' => 'datetime',
        'original_values' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(HrAttendanceSession::class, 'attendance_session_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(HrTimesheet::class, 'hr_timesheet_id');
    }

    public function amendedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }

    public function amendments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(HrTimeEntryAmendment::class, 'hr_time_entry_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('entry_date', [$from, $to]);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('clock_out');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeForShift($query, int $shiftId)
    {
        return $query->where('shift_id', $shiftId);
    }

    public function scopeClockedInToday($query)
    {
        return $query->where('entry_date', now()->toDateString())
            ->whereNull('clock_out');
    }

    public function scopeForTeam($query, int $managerUserId)
    {
        $teamUserIds = HrEmployeeProfile::where('manager_user_id', $managerUserId)
            ->where('is_active', true)
            ->pluck('user_id');

        return $query->whereIn('user_id', $teamUserIds);
    }

    public function scopeForUserOrTeam($query, int $userId, array $teamUserIds)
    {
        return $query->where(function ($q) use ($userId, $teamUserIds) {
            $q->where('user_id', $userId)
              ->orWhereIn('user_id', $teamUserIds);
        });
    }
}
