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
        'dose_times',
        'is_prn',
        'controlled_drug',
        'prn_reason',
        'max_per_day',
        'route',
        'form',
        'prescriber',
        'indication',
        'pharmacy',
        'start_date',
        'end_date',
        'review_date',
        'ceased_at',
        'ceased_reason',
        'instructions',
        'active',
        'state',
        'paused_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'review_date' => 'date',
        'ceased_at' => 'date',
        'paused_at' => 'datetime',
        'is_prn' => 'boolean',
        'controlled_drug' => 'boolean',
        'active' => 'boolean',
        'dose_times' => 'array',
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
