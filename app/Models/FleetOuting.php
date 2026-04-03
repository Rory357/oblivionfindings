<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetOuting extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'title',
        'destination',
        'purpose',
        'planned_departure',
        'planned_return',
        'actual_departure',
        'actual_return',
        'asset_id',
        'driver_user_id',
        'booking_id',
        'risk_assessment',
        'status',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'planned_departure' => 'datetime',
        'planned_return' => 'datetime',
        'actual_departure' => 'datetime',
        'actual_return' => 'datetime',
        'risk_assessment' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FleetVehicleBooking::class, 'booking_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function residents(): HasMany
    {
        return $this->hasMany(FleetOutingResident::class, 'outing_id');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'fleet_outing_residents', 'outing_id', 'client_id')
            ->withPivot(['pre_check_completed', 'medication_packed', 'notes'])
            ->withTimestamps();
    }

    public function getDurationMinutesAttribute(): ?float
    {
        $start = $this->actual_departure ?? $this->planned_departure;
        $end = $this->actual_return ?? $this->planned_return;

        if (!$start || !$end) {
            return null;
        }

        return round($start->diffInMinutes($end), 1);
    }
}
