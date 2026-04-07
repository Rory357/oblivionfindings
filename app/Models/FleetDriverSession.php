<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetDriverSession extends Model
{
    use AuditableChanges;
    protected $fillable = [
        'asset_id',
        'user_id',
        'started_at',
        'ended_at',
        'source',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(FleetTrip::class, 'driver_session_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(FleetSignal::class, 'driver_session_id');
    }
}
