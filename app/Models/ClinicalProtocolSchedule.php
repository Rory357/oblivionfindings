<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalProtocolSchedule extends Model
{
    protected $fillable = [
        'clinical_protocol_id',
        'day_of_week',
        'preferred_time',
    ];

    protected $casts = [
        'preferred_time' => 'string',
    ];

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(ClinicalProtocol::class, 'clinical_protocol_id');
    }
}
