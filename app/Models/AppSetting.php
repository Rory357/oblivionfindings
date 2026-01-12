<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        // Supports either scalar (e.g. "Patient") or object/array values.
        'value' => 'json',
    ];
}
