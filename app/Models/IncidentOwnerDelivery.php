<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentOwnerDelivery extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['delivered_at' => 'datetime', 'attempts' => 'integer'];

    public function change(): BelongsTo
    {
        return $this->belongsTo(IncidentOwnerChange::class, 'change_id');
    }
}
