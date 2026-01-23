<?php

namespace App\Models;

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
        'user_id',
        'starts_at',
        'ends_at',
        'location',
        'notes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function series()
    {
        return $this->belongsTo(ShiftSeries::class, 'shift_series_id');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'user_id');
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
}
