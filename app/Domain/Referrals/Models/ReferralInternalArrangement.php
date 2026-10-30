<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/** Exact proposal terms and company risk acceptance; never an external funding award. */
class ReferralInternalArrangement extends Model
{
    use AppendOnly;

    protected $table = 'care_referral_internal_arrangements';

    public $timestamps = false;

    protected $fillable = ['referral_id', 'proposal_id', 'terms', 'authored_by', 'authored_at'];

    protected $hidden = ['terms'];

    protected $casts = ['terms' => 'encrypted:array', 'authored_at' => 'immutable_datetime'];
}
