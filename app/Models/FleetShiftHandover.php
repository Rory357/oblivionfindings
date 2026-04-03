<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetShiftHandover extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'organisation_id',
        'asset_id',
        'outgoing_user_id',
        'incoming_user_id',
        'odometer_km',
        'fuel_level',
        'exterior_condition',
        'interior_condition',
        'keys_present',
        'documents_present',
        'first_aid_kit',
        'fire_extinguisher',
        'damage_notes',
        'notes',
        'status',
        'handed_over_at',
        'accepted_at',
    ];

    protected $casts = [
        'keys_present' => 'boolean',
        'documents_present' => 'boolean',
        'first_aid_kit' => 'boolean',
        'fire_extinguisher' => 'boolean',
        'damage_notes' => 'array',
        'handed_over_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function outgoingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outgoing_user_id');
    }

    public function incomingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'incoming_user_id');
    }
}
