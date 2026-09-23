<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The check a vehicle is expected to have: its checklist and the person who
 * owns it. The next due date is the vehicle's inspection_due_at.
 */
class FleetVehicleCheckRequirement extends Model
{
    protected $fillable = [
        'asset_id',
        'template_id',
        'owner_user_id',
        'lock_version',
        'updated_by_user_id',
    ];

    protected $casts = [
        'lock_version' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FleetChecklistTemplate::class, 'template_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
