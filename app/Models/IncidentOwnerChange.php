<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class IncidentOwnerChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime', 'revision' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incident ownership history is append-only.'));
        static::deleting(fn () => throw new LogicException('Incident ownership history is append-only.'));
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'incident_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(IncidentOwnerDelivery::class, 'change_id');
    }
}
