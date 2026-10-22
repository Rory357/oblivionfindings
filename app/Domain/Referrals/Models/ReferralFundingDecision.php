<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class ReferralFundingDecision extends Model
{
    use AppendOnly;

    protected $table = 'care_referral_funding_decisions';

    public $timestamps = false;

    protected $fillable = [
        'funding_case_id',
        'version',
        'outcome',
        'decision',
        'recorded_by',
        'recorded_at',
    ];

    protected $hidden = ['decision'];

    protected $casts = [
        'decision' => 'encrypted:array',
        'recorded_at' => 'immutable_datetime',
        'version' => 'integer',
    ];
}
