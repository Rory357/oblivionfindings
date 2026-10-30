<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicineCatalogueBinding extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['medicine_identity_sha256'];

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'medication_version' => 'integer'];
    }
}
