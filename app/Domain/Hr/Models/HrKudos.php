<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrKudos extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\Hr\HrKudosFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'from_user_id',
        'to_user_id',
        'category',
        'message',
        'is_public',
        'feed_post_id',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function feedPost(): BelongsTo
    {
        return $this->belongsTo(HrFeedPost::class, 'feed_post_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(HrKudosReaction::class, 'kudos_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(HrKudosReply::class, 'kudos_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }
}
