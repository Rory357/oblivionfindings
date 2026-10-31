<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class ReferralProposal extends Model
{
    use AppendOnly;

    protected $table = 'care_referral_proposals';

    public $timestamps = false;

    protected $fillable = [
        'referral_id',
        'version',
        'proposal',
        'authored_by',
        'authored_at',
    ];

    protected $hidden = ['proposal'];

    protected $casts = [
        'proposal' => 'encrypted:array',
        'authored_at' => 'immutable_datetime',
        'version' => 'integer',
    ];
}
