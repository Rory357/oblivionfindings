<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MedicationPharmacyAcknowledgment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['received_at' => 'immutable_datetime', 'applied' => 'boolean'];
}
