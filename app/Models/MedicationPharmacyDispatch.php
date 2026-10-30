<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MedicationPharmacyDispatch extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'snapshot_fingerprint', 'partner_fingerprint', 'claim_token'];

    protected $casts = [
        'snapshot' => 'encrypted:array', 'connection_version' => 'integer', 'attempt_count' => 'integer',
        'sending_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime',
        'acknowledgment_applied' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(MedicationPharmacyOrder::class, 'pharmacy_order_id');
    }

    public function connection()
    {
        return $this->belongsTo(MedicationPharmacyConnection::class, 'connection_id');
    }
}
