<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetOutingResident extends Model
{
    protected $fillable = [
        'outing_id',
        'client_id',
        'pre_check_completed',
        'medication_packed',
        'notes',
    ];

    protected $casts = [
        'pre_check_completed' => 'boolean',
        'medication_packed' => 'boolean',
    ];

    public function outing(): BelongsTo
    {
        return $this->belongsTo(FleetOuting::class, 'outing_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
