<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetMedicationTransitLog extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'transport_id',
        'outing_id',
        'client_id',
        'medication_id',
        'medication_name',
        'is_controlled_drug',
        'packed_by_user_id',
        'packed_at',
        'administered_at',
        'administered_by_user_id',
        'witnessed_by_user_id',
        'returned_to_house_at',
        'notes',
    ];

    protected $casts = [
        'is_controlled_drug' => 'boolean',
        'packed_at' => 'datetime',
        'administered_at' => 'datetime',
        'returned_to_house_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'medication_id');
    }

    public function transport(): BelongsTo
    {
        return $this->belongsTo(FleetResidentTransport::class, 'transport_id');
    }

    public function outing(): BelongsTo
    {
        return $this->belongsTo(FleetOuting::class, 'outing_id');
    }

    public function packedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by_user_id');
    }

    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by_user_id');
    }

    public function witnessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witnessed_by_user_id');
    }

    public function getStatusAttribute(): string
    {
        if ($this->returned_to_house_at) {
            return 'returned';
        }
        if ($this->administered_at) {
            return 'administered';
        }
        return 'packed';
    }
}
