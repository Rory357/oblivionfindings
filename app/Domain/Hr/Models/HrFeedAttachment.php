<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image attached to a community-wall post, stored on the private disk and
 * served through the hardened {@see \App\Http\Controllers\Concerns\ServesPrivateAttachments}
 * route.
 */
class HrFeedAttachment extends Model
{
    protected $fillable = [
        'tenant_id',
        'feed_post_id',
        'uploaded_by',
        'disk',
        'original_name',
        'path',
        'mime',
        'size',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(HrFeedPost::class, 'feed_post_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
