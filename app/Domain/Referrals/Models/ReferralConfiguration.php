<?php

namespace App\Domain\Referrals\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralConfiguration extends Model
{
    protected $table = 'care_referral_configuration';

    protected $fillable = [
        'id',
        'published_version_id',
        'lock_version',
    ];

    protected $hidden = [];

    protected $casts = [
        'lock_version' => 'integer',
    ];
}
