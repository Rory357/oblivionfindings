<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMedication extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'name',
        'dosage',
        'frequency',
        'is_prn',
        'prn_reason',
        'max_per_day',
        'route',
        'prescriber',
        'start_date',
        'end_date',
        'instructions',
        'active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_prn' => 'boolean',
        'active' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function stock()
    {
        return $this->hasOne(ClientMedicationStock::class, 'client_medication_id');
    }

    public function administrations()
    {
        return $this->hasMany(ClientMedicationAdministration::class, 'client_medication_id');
    }
}
