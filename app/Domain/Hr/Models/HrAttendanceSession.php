<?php

namespace App\Domain\Hr\Models;

use App\Domain\Hr\Enums\AttendanceTimesheetSyncOutcome;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftSafetyInvariantService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HrAttendanceSession extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'shift_id',
        'site_id',
        'clock_in_at',
        'clock_out_at',
        'break_minutes',
        'break_started_at',
        'break_count',
        'status',
        'source',
        'location',
        'notes',
        'meta',
        'created_by',
        'closed_by',
    ];

    protected $casts = [
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'break_started_at' => 'datetime',
        'break_minutes' => 'integer',
        'break_count' => 'integer',
        'meta' => 'array',
    ];

    private AttendanceTimesheetSyncOutcome $timesheetSyncOutcome = AttendanceTimesheetSyncOutcome::None;

    protected static function booted(): void
    {
        static::saving(function (self $session): void {
            app(ShiftSafetyInvariantService::class)->assertAttendanceSession($session);
        });
    }

    public function markTimesheetSyncOutcome(AttendanceTimesheetSyncOutcome $outcome): self
    {
        $this->timesheetSyncOutcome = $outcome;

        return $this;
    }

    public function timesheetSyncOutcome(): AttendanceTimesheetSyncOutcome
    {
        return $this->timesheetSyncOutcome;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function timesheet(): HasOne
    {
        return $this->hasOne(Timesheet::class, 'attendance_session_id');
    }

    public function breakEvents(): HasMany
    {
        return $this->hasMany(HrAttendanceBreakEvent::class, 'session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open')->whereNull('clock_out_at');
    }

    public function getWorkedHoursAttribute(): float
    {
        if (! $this->clock_in_at || ! $this->clock_out_at) {
            return 0.0;
        }

        $minutes = $this->clock_in_at->diffInMinutes($this->clock_out_at) - max((int) $this->break_minutes, 0);

        return round(max($minutes, 0) / 60, 2);
    }
}
