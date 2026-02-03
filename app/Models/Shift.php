<?php

namespace App\Models;

use App\Models\ClientIncident;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'shift_series_id',
        'client_id',
        'respite_booking_id',
        'service_context_id',
        'user_id',
        'starts_at',
        'ends_at',
        'actual_starts_at',
        'actual_ends_at',
        'started_by',
        'completed_by',
        'location',
        'notes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'actual_starts_at' => 'datetime',
        'actual_ends_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
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

    public function timesheets()
    {
        return $this->hasMany(Timesheet::class);
    }

    public function tasks()
    {
        return $this->hasMany(ShiftTask::class)->orderBy('sort_order');
    }

    public function incidents()
    {
        return $this->hasMany(ClientIncident::class);
    }

    public function isEnded(): bool
    {
        $end = $this->actual_ends_at ?? $this->ends_at;
        return $end ? now()->greaterThanOrEqualTo($end) : false;
    }

}
