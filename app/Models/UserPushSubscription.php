<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'token',
        'keys',
        'device_id',
        'platform',
        'enabled',
        'last_used_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    protected function keys(): Attribute
    {
        return Attribute::make(
            get: function ($value): ?array {
                $keys = is_string($value) ? json_decode($value, true) : $value;
                if (! is_array($keys)) {
                    return null;
                }

                return [
                    'p256dh' => $keys['p256dh'] ?? null,
                    'auth' => $keys['auth'] ?? null,
                ];
            },
            set: fn ($value) => is_array($value)
                ? json_encode([
                    'p256dh' => $value['p256dh'] ?? null,
                    'auth' => $value['auth'] ?? null,
                ])
                : $value,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
