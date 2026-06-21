<?php

namespace App\Models;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Rostering\RosteringFeatureFlags;
use App\Domain\Rostering\RosterPublishingService;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Shift extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'shift_series_id',
        'client_id',
        'site_id',
        'respite_booking_id',
        'service_context_id',
        'user_id',
        'starts_at',
        'ends_at',
        'actual_starts_at',
        'actual_ends_at',
        'started_by',
        'completed_by',
        'handover_waiver_reason',
        'handover_waived_at',
        'handover_waived_by',
        'location',
        'notes',
        'status',
        'shift_type',
        'is_sleepover',
        'is_on_call',
        'is_lone_worker',
        'expected_break_minutes',
        'coverage_roles',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'actual_starts_at' => 'datetime',
        'actual_ends_at' => 'datetime',
        'handover_waived_at' => 'datetime',
        'published_at' => 'datetime',
        'publish_dirty_at' => 'datetime',
        'is_sleepover' => 'boolean',
        'is_on_call' => 'boolean',
        'is_lone_worker' => 'boolean',
        'expected_break_minutes' => 'integer',
        'coverage_roles' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $shift): void {
            if (! $shift->organization_id) {
                $clientOrganizationId = $shift->client_id
                    ? Client::query()->whereKey($shift->client_id)->value('organization_id')
                    : null;

                $shift->organization_id = $clientOrganizationId
                    ?: auth()->user()?->organization_id
                    ?: 1;
            }
        });

        static::saving(function (self $shift): void {
            app(\App\Services\ShiftSafetyInvariantService::class)->assertShift($shift);
        });

        static::updating(function (self $shift): void {
            if (! $shift->hasApprovedTimesheet()) {
                return;
            }

            $payrollCriticalFields = [
                'client_id',
                'site_id',
                'service_context_id',
                'user_id',
                'starts_at',
                'ends_at',
                'shift_type',
                'is_sleepover',
                'is_on_call',
                'expected_break_minutes',
            ];

            if ($shift->isDirty($payrollCriticalFields)) {
                throw ValidationException::withMessages([
                    'shift' => 'This shift has an approved timesheet and payroll-critical fields can no longer be changed.',
                ]);
            }

            if ($shift->isDirty('status') && $shift->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'shift' => 'This shift has an approved timesheet and cannot be cancelled.',
                ]);
            }
        });

        static::updating(function (self $shift): void {
            app(RosterPublishingService::class)->markDirtyFromShiftUpdate($shift);
        });

        static::created(function (self $shift): void {
            app(RosterPublishingService::class)->markDirtyFromShiftCreate($shift);
        });

        static::deleting(function (self $shift): void {
            app(RosterPublishingService::class)->markDirtyFromShiftDelete($shift);
        });
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function rosterPeriod()
    {
        return $this->belongsTo(RosterPeriod::class);
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function series()
    {
        return $this->belongsTo(ShiftSeries::class, 'shift_series_id');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function respiteBooking()
    {
        return $this->belongsTo(RespiteBooking::class, 'respite_booking_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function handoverWaiverAuthor()
    {
        return $this->belongsTo(User::class, 'handover_waived_by');
    }

    public function timesheets()
    {
        return $this->hasMany(Timesheet::class);
    }

    public function attendanceSessions()
    {
        return $this->hasMany(HrAttendanceSession::class);
    }

    public function signals()
    {
        return $this->hasMany(ShiftSignal::class);
    }

    /**
     * The lone worker safety session monitoring this shift, if one was started.
     * A safety overlay — linked, never merged (payroll-critical fields stay on Shift).
     */
    public function loneWorkerSession()
    {
        return $this->hasOne(LoneWorkerSession::class);
    }

    public function approvedTimesheets()
    {
        return $this->timesheets()->where('status', 'approved');
    }

    public function scopeVisibleToFrontline(Builder $query, ?int $organizationId = null): Builder
    {
        if (app(RosteringFeatureFlags::class)->publishEnabled($organizationId)) {
            $query->whereNotNull('published_at');
        }

        return $query;
    }

    public function formSubmissions()
    {
        return $this->hasMany(\App\Models\CustomFormSubmission::class);
    }

    public function medicationAdministrations()
    {
        return $this->hasMany(\App\Models\ClientMedicationAdministration::class);
    }

    public function tasks()
    {
        return $this->hasMany(ShiftTask::class)->orderBy('sort_order');
    }

    public function incidents()
    {
        return $this->hasMany(ClientIncident::class);
    }

    public function residentTransports()
    {
        return $this->hasMany(\App\Models\FleetResidentTransport::class);
    }

    public function clientNotes()
    {
        return $this->hasMany(\App\Models\ClientNote::class, 'shift_id');
    }

    public function outgoingHandovers()
    {
        return $this->hasMany(\App\Models\ShiftHandover::class, 'outgoing_shift_id');
    }

    public function incomingHandovers()
    {
        return $this->hasMany(\App\Models\ShiftHandover::class, 'incoming_shift_id');
    }

    public function replacementRequests()
    {
        return $this->hasMany(\App\Models\ShiftReplacementRequest::class)->orderByDesc('requested_at');
    }

    public function openPositions()
    {
        return $this->hasMany(ShiftOpenPosition::class);
    }

    public function isEnded(): bool
    {
        $end = $this->actual_ends_at ?? $this->ends_at;

        return $end ? now()->greaterThanOrEqualTo($end) : false;
    }

    public function getIsLateAttribute(): bool
    {
        if (! $this->starts_at) {
            return false;
        }

        $threshold = $this->starts_at->copy()->addMinutes(5);

        if ($this->actual_starts_at) {
            return $this->actual_starts_at->greaterThan($threshold);
        }

        return $this->status === 'scheduled' && now()->greaterThan($threshold);
    }

    public function getIsMissedAttribute(): bool
    {
        if (! $this->ends_at) {
            return false;
        }

        return $this->status === 'scheduled'
            && ! $this->actual_starts_at
            && now()->greaterThan($this->ends_at);
    }

    public function hasApprovedTimesheet(): bool
    {
        if (! $this->exists) {
            return false;
        }

        if ($this->relationLoaded('timesheets')) {
            return $this->timesheets->contains(fn (Timesheet $timesheet) => $timesheet->status === 'approved');
        }

        return $this->approvedTimesheets()->exists();
    }
}
