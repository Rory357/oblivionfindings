<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetResidentTransportEvent extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'transport_id',
        'medication_transit_log_id',
        'client_id',
        'site_id',
        'shift_id',
        'asset_id',
        'medication_id',
        'medication_order_version_id',
        'medication_administration_id',
        'action',
        'actor_user_id',
        'witness_user_id',
        'request_uuid',
        'occurred_at',
        'previous_event_hash',
        'event_hash',
        'context',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'context' => 'array',
    ];

    public function transport(): BelongsTo
    {
        return $this->belongsTo(FleetResidentTransport::class);
    }

    public function medicationTransitLog(): BelongsTo
    {
        return $this->belongsTo(FleetMedicationTransitLog::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class);
    }

    public function medicationOrderVersion(): BelongsTo
    {
        return $this->belongsTo(MedicationOrderVersion::class);
    }

    public function medicationAdministration(): BelongsTo
    {
        return $this->belongsTo(ClientMedicationAdministration::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function witness(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witness_user_id');
    }
}
