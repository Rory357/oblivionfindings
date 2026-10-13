<?php

namespace App\Domain\SecurityDevices\Endpoints\Models;

use Illuminate\Database\Eloquent\Model;

/** Ephemeral connection descriptions, never screen content or ticket evidence. */
final class EndpointRemoteSignal extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['offer_sdp', 'answer_sdp', 'viewer_uuid'];

    protected $casts = ['offer_sdp' => 'encrypted', 'answer_sdp' => 'encrypted'];
}
