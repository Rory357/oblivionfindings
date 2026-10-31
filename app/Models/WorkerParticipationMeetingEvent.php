<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Private source descriptor includes original location equality evidence; generic notices disclose none of it. */
final class WorkerParticipationMeetingEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['sequence' => 'integer', 'source_descriptor' => 'array', 'audience_membership' => 'array'];

    public function deliveries(): HasMany
    {
        return $this->hasMany(WorkerParticipationMeetingDelivery::class, 'event_id');
    }
}
