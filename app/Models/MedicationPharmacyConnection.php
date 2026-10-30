<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MedicationPharmacyConnection extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['site_ids' => 'array', 'enabled' => 'boolean', 'version' => 'integer'];

    public function dispatches()
    {
        return $this->hasMany(MedicationPharmacyDispatch::class, 'connection_id');
    }
}
