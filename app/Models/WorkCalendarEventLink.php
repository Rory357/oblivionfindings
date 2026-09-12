<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkCalendarEventLink extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['ends_at' => 'datetime', 'last_synced_at' => 'datetime'];
}
