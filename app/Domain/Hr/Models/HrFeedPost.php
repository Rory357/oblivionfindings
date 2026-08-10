<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HrFeedPost extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'post_type',
        'kind',
        'target_audience',
        'target_value',
        'content',
        'is_pinned',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kudos(): HasOne
    {
        return $this->hasOne(HrKudos::class, 'feed_post_id');
    }

    public function attachment(): HasOne
    {
        return $this->hasOne(HrFeedAttachment::class, 'feed_post_id');
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }
}
