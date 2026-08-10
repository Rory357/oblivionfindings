<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMedicationStock extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'client_medication_id',
        'on_hand',
        'unit',
        'reorder_level',
        'last_counted_at',
        'last_reorder_alert_at',
        'notes',
    ];

    protected $casts = [
        'last_counted_at' => 'datetime',
        'last_reorder_alert_at' => 'datetime',
    ];

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }
}
