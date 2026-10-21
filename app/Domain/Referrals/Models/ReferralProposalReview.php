<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class ReferralProposalReview extends Model
{
    use AppendOnly;

    protected $table = 'care_referral_proposal_reviews';

    public $timestamps = false;

    protected $fillable = ['proposal_id', 'kind', 'funding_case_id', 'result', 'details', 'recorded_by', 'recorded_at'];

    protected $hidden = ['details'];

    protected $casts = ['details' => 'encrypted:array', 'recorded_at' => 'immutable_datetime'];
}
