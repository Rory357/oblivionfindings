<?php

namespace App\Domain\Referrals\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralOutbox extends Model
{
    protected $table = 'care_referral_outbox';

    protected $fillable = [
        'referral_id',
        'deduplication_key',
        'kind',
        'state',
        'message',
        'attempts',
        'available_at',
        'claimed_at',
        'claim_token',
        'sent_at',
        'delivered_at',
        'last_error_code',
    ];

    protected $hidden = ['message'];

    protected $casts = [
        'message' => 'encrypted:array',
        'attempts' => 'integer',
        'available_at' => 'immutable_datetime',
        'claimed_at' => 'immutable_datetime',
        'sent_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];
}
