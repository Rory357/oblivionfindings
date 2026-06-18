<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fleet & Asset Incidents redesign — Step 1 (Gap F3). A trackable operational
 * follow-up on a fleet/asset incident (assign / due / complete). Mirrors
 * `IncidentFollowup` for client incidents.
 */
class FleetIncidentFollowup extends Model
{
    use AuditableChanges, HasFactory;

    protected $table = 'fleet_incident_followups';

    protected $fillable = [
        'fleet_incident_id',
        'assigned_to_user_id',
        'due_at',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(FleetIncident::class, 'fleet_incident_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return ! empty($this->completed_at);
    }
}
