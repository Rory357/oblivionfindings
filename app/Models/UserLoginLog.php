<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class UserLoginLog extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'event_type',
        'ip_address',
        'user_agent',
        'location',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ---------------------------
    // Relationships
    // ---------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---------------------------
    // Scopes
    // ---------------------------

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ---------------------------
    // Static helpers
    // ---------------------------

    public static function record(string $eventType, ?int $userId, ?string $ip, ?string $ua, array $meta = []): static
    {
        $attributes = [
            'user_id' => $userId,
            'event_type' => $eventType,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'metadata' => !empty($meta) ? $meta : null,
            'created_at' => now(),
        ];

        if (! Schema::hasTable((new static())->getTable())) {
            return new static($attributes);
        }

        return static::create($attributes);
    }
}
