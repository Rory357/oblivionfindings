<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class ReferralCareHandover extends Model
{
    use AppendOnly;

    protected $table = 'care_referral_care_handovers';

    public $timestamps = false;

    protected $fillable = ['conversion_id', 'version', 'submission_key', 'intent_hash', 'snapshot', 'published_by', 'published_at'];

    protected $hidden = ['snapshot', 'submission_key', 'intent_hash'];

    protected $casts = ['snapshot' => 'encrypted:array', 'version' => 'integer', 'published_at' => 'immutable_datetime'];
}
