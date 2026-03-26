<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationRoundTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_context_id',
        'site_id',
        'name',
        'scheduled_time',
        'window_minutes',
        'days_of_week',
        'active',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'active' => 'boolean',
        'window_minutes' => 'integer',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function appliesToDay(int $dayOfWeek): bool
    {
        if (empty($this->days_of_week)) return true;
        return in_array($dayOfWeek, $this->days_of_week);
    }
}
