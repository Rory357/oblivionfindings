<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\HasReferralReference;
use Illuminate\Database\Eloquent\Model;

final class ReferralDocumentRequest extends Model
{
    use HasReferralReference;

    protected $table = 'care_referral_document_requests';

    protected $guarded = ['id'];

    protected $hidden = ['details', 'credentials', 'token_hash', 'passcode_hash', 'request_hash'];

    protected $casts = [
        'details' => 'encrypted:array', 'credentials' => 'encrypted:array',
        'expires_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime',
        'failed_attempts' => 'integer',
    ];
}
