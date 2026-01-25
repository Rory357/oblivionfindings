<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentFollowup extends Model
{
    use AuditableChanges;

    protected $table = 'incident_followups';

    protected $fillable = [
        'client_incident_id',
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
        return $this->belongsTo(ClientIncident::class, 'client_incident_id');
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
        return !empty($this->completed_at);
    }
}
