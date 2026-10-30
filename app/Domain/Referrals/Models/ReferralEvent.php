<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\AppendOnly;
use App\Domain\Referrals\Models\Concerns\HasReferralReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralEvent extends Model
{
    use AppendOnly;
    use HasReferralReference;

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected $table = 'care_referral_events';

    public $timestamps = false;

    protected $fillable = [
        'reference',
        'referral_id',
        'actor_id',
        'kind',
        'audience',
        'subject_type',
        'subject_id',
        'payload',
        'occurred_at',
    ];

    protected $hidden = ['payload'];

    protected $casts = [
        'payload' => 'encrypted:array',
        'occurred_at' => 'immutable_datetime',
    ];
}
