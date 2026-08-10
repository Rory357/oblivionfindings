<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrEapReferral extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'staff_user_id',
        'referred_by',
        'reason_category',
        'provider',
        'status',
        'consent_given',
        'is_self_referral',
        'notes',
    ];

    protected $casts = [
        'consent_given' => 'boolean',
        'is_self_referral' => 'boolean',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }
}
