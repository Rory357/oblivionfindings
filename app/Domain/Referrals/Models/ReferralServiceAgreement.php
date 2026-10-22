<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * The draft service agreement created from one approved and accepted support proposal version.
 * One row per proposal version: a retry returns the same agreement, and a newer version never
 * edits an earlier agreement.
 */
class ReferralServiceAgreement extends Model
{
    use AppendOnly;

    protected $table = 'care_referral_service_agreements';

    public $timestamps = false;

    protected $fillable = [
        'referral_id',
        'proposal_id',
        'proposal_version',
        'client_id',
        'service_agreement_id',
        'created_by',
        'mapping_digest',
        'receipt',
        'created_at',
    ];

    protected $hidden = ['receipt'];

    protected $casts = [
        'receipt' => 'encrypted:array',
        'proposal_version' => 'integer',
        'created_at' => 'immutable_datetime',
    ];
}
