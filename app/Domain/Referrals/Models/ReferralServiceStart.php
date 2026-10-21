<?php

namespace App\Domain\Referrals\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralServiceStart extends Model
{
    protected $table = 'care_referral_service_starts';

    protected $fillable = [
        'referral_id',
        'client_id',
        'proposal_id',
        'service_agreement_id',
        'approved_by',
        'status',
        'agreed_date',
        'commenced_at',
        'handover',
        'lock_version',
    ];

    protected $hidden = ['handover'];

    protected $casts = [
        'handover' => 'encrypted:array',
        'agreed_date' => 'immutable_date',
        'commenced_at' => 'immutable_datetime',
        'lock_version' => 'integer',
    ];
}
