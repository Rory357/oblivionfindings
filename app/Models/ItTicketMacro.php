<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ItTicketMacro extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'actions' => 'array',
        'is_active' => 'boolean',
        'lock_version' => 'integer',
    ];
}
