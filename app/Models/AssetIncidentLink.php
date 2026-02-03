<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetIncidentLink extends Model
{
    protected $fillable = [
        'asset_id',
        'incident_id',
        'relation',
        'notes',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'incident_id');
    }
}
