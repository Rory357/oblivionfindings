<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ClientControlledDrugDiscrepancy extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'service_context_id',
        'incident_id',
        'on_hand_before',
        'on_hand_after',
        'difference',
        'reason',
        'notes',
        'immediate_action_taken',
        'reported_at',
        'reported_by',
        'witnessed_by',
        'status',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
    ];

    protected $casts = [
        'on_hand_before' => 'decimal:2',
        'on_hand_after' => 'decimal:2',
        'difference' => 'decimal:2',
        'reported_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function serviceContext(): BelongsTo
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'incident_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function witnessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witnessed_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(MedicationMarAttachment::class, 'attachable')
            ->latest('id');
    }
}
