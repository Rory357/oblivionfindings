<?php

namespace App\Domain\Referrals\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralSubmission extends Model
{
    protected $table = 'care_referral_submissions';

    protected $fillable = [
        'idempotency_hash',
        'payload_hash',
        'receipt_hash',
        'referral_id',
        'expires_at',
    ];

    protected $hidden = ['idempotency_hash', 'payload_hash', 'receipt_hash'];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
    ];
}
