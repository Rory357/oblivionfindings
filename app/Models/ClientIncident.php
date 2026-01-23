<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientIncident extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'reported_by',
        'shift_id',
        'type',
        'severity',
        'status',
        'occurred_at',
        'location',
        'title',
        'description',
        'immediate_action',
        'follow_up_required',
        'reviewed_by',
        'reviewed_at',
        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClientIncidentAttachment::class, 'incident_id');
    }
}
