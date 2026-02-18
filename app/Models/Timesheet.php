<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Timesheet extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'user_id',
        'client_id',
        'shift_id',
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
    ];

    protected $casts = [
        'work_date' => 'date',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'mileage_km' => 'decimal:2',
        'sleepover' => 'boolean',
        'on_call' => 'boolean',
        'public_holiday' => 'boolean',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'returned_at' => 'datetime',
        'is_residential_billable' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
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

    public function getTotalHoursAttribute(): float
    {
        if (! $this->starts_at || ! $this->ends_at) {
            return 0.0;
        }

        $minutes = $this->starts_at->diffInMinutes($this->ends_at) - (int) $this->break_minutes;
        return round(max($minutes, 0) / 60, 2);
    }
}
