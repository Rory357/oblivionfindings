<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MedicationProviderTransferEvent extends Model
{
    protected $table = 'medication_provider_transfer_events';

    protected $guarded = ['id'];

    protected $casts = ['evidence' => 'encrypted:array', 'created_at' => 'datetime'];

    public $timestamps = false;
}
