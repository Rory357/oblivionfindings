<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ItKbWorkingCopy extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'encrypted:array', 'site_scope' => 'array', 'lock_version' => 'integer', 'submitted_at' => 'datetime'];
}
