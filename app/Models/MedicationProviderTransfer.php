<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MedicationProviderTransfer extends Model
{
    protected $table = 'medication_provider_transfers';

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'encrypted:array', 'identity_evidence' => 'encrypted', 'receipt_reference' => 'encrypted', 'source_reference' => 'encrypted', 'reviewed_at' => 'datetime', 'received_at' => 'datetime', 'version' => 'integer', 'controlled' => 'boolean'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function events()
    {
        return $this->hasMany(MedicationProviderTransferEvent::class, 'transfer_id');
    }

    public function reconciliation()
    {
        return $this->belongsTo(MedicationReconciliation::class, 'reconciliation_id');
    }
}
