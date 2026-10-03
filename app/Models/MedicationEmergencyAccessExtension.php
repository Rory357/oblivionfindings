<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationEmergencyAccessExtension extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['access_id', 'user_id', 'previous_expires_at', 'expires_at', 'reason', 'created_at'];

    protected $casts = ['previous_expires_at' => 'datetime', 'expires_at' => 'datetime', 'created_at' => 'datetime'];
}
