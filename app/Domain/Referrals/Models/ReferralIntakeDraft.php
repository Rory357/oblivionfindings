<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\HasReferralReference;
use Illuminate\Database\Eloquent\Model;

/** A staff member's own unsubmitted intake. Encrypted, short-lived and never part of the referral record. */
class ReferralIntakeDraft extends Model
{
    use HasReferralReference;

    protected $table = 'care_referral_intake_drafts';

    protected $fillable = ['reference', 'user_id', 'payload', 'expires_at'];

    protected $hidden = ['payload'];

    protected $casts = [
        'payload' => 'encrypted:array',
        'expires_at' => 'immutable_datetime',
    ];
}
