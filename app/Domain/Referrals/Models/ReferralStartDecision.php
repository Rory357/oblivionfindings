<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class ReferralStartDecision extends Model
{
    use AppendOnly;

    protected $table = 'care_referral_start_decisions';

    public $timestamps = false;

    protected $fillable = ['referral_id', 'service_start_id', 'submission_key', 'operation', 'intent_hash', 'decision', 'recorded_by', 'recorded_at'];

    protected $hidden = ['submission_key', 'intent_hash', 'decision'];

    protected $casts = ['decision' => 'encrypted:array', 'recorded_at' => 'immutable_datetime'];
}
