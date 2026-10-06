<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MedicationPharmacyDispatchCommand extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['result', 'fingerprint'];

    protected $casts = ['result' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
}
