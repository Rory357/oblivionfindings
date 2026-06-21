<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftSeries extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $table = 'shift_series';

    protected $fillable = [
        'client_id',
        'site_id',
        'service_context_id',
        'user_id',
        'start_date',
        'end_date',
        'timezone',
        'by_weekday',
        'starts_time',
        'ends_time',
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
        'start_date' => 'date',
        'end_date' => 'date',
        'by_weekday' => 'array',
        'is_sleepover' => 'boolean',
        'is_on_call' => 'boolean',
        'is_lone_worker' => 'boolean',
        'expected_break_minutes' => 'integer',
        'coverage_roles' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class, 'shift_series_id');
    }
}
