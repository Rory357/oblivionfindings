<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-use, 15-minute family portal sign-in link, bound to the browser that
 * requested it. Only hashes of the token and the device secret are stored.
 */
class PortalSignInLink extends Model
{
    public const VALID_FOR_MINUTES = 15;

    public const DEVICE_COOKIE = 'portal_sign_in_device';

    protected $fillable = [
        'user_id',
        'token_hash',
        'device_hash',
        'expires_at',
        'used_at',
        'revoked_at',
        'requested_ip',
    ];

    protected $hidden = ['token_hash', 'device_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('used_at')->whereNull('revoked_at');
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
