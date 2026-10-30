<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\HasReferralReference;
use Illuminate\Database\Eloquent\Model;

class ReferralEvidence extends Model
{
    use HasReferralReference;

    protected $table = 'care_referral_evidence';

    protected $fillable = [
        'reference',
        'referral_id',
        'previous_version_id',
        'audience',
        'state',
        'disk',
        'storage_path',
        'sha256',
        'size_bytes',
        'metadata',
        'uploaded_by',
    ];

    protected $hidden = ['metadata', 'disk', 'storage_path', 'sha256'];

    protected $casts = [
        'metadata' => 'encrypted:array',
        'size_bytes' => 'integer',
    ];
}
