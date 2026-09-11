<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable nonsecret outcome only. A credential can never be recovered here. */
class ItServiceIdentityCommandReceipt extends Model
{
    protected $fillable = [
        'actor_user_id', 'request_uuid', 'operation', 'service_identity_id',
        'payload_hash', 'state', 'result_version',
    ];

    protected $hidden = ['payload_hash'];

    protected $casts = ['result_version' => 'integer'];
}
