<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\HasReferralReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralWorkItem extends Model
{
    use HasReferralReference;

    public function referral(): BelongsTo
    {
        return $this->belongsTo(CareReferral::class, 'referral_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function fundingCase(): BelongsTo
    {
        return $this->belongsTo(ReferralFundingCase::class, 'funding_case_id');
    }

    protected $table = 'care_referral_work_items';

    protected $fillable = [
        'reference',
        'referral_id',
        'funding_case_id',
        'owner_id',
        'kind',
        'audience',
        'status',
        'details',
        'due_at',
        'ends_at',
        'lock_version',
    ];

    protected $hidden = ['details'];

    protected $casts = [
        'details' => 'encrypted:array',
        'due_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'lock_version' => 'integer',
    ];
}
