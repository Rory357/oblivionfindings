<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\HasReferralReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralFundingCase extends Model
{
    use HasReferralReference;

    public function navigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'navigator_id');
    }

    protected $table = 'care_referral_funding_cases';

    protected $fillable = [
        'reference',
        'referral_id',
        'navigator_id',
        'settings_version_id',
        'previous_case_id',
        'pathway',
        'status',
        'personal_income',
        'details',
        'lock_version',
    ];

    protected $hidden = ['details'];

    protected $casts = [
        'details' => 'encrypted:array',
        'personal_income' => 'boolean',
        'lock_version' => 'integer',
    ];
}
