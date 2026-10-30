<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

class ReferralConversion extends Model
{
    use AppendOnly;

    protected $table = 'care_referral_conversions';

    public $timestamps = false;

    protected $fillable = [
        'referral_id',
        'client_id',
        'workflow_id',
        'converted_by',
        'created_person',
        'receipt',
        'converted_at',
    ];

    protected $hidden = ['receipt'];

    protected $casts = [
        'receipt' => 'encrypted:array',
        'created_person' => 'boolean',
        'converted_at' => 'immutable_datetime',
    ];
}
