<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMedicationAdministration extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'shift_id',
        'administered_by',
        'scheduled_for',
        'administered_at',
        'status',
        'dose_given',
        'notes',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'administered_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function administeredBy()
    {
        return $this->belongsTo(User::class, 'administered_by');
    }
}
