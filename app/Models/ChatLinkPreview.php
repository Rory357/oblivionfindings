<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One cached preview per address, keyed by its SHA-256 and fetched by the server only. */
class ChatLinkPreview extends Model
{
    protected $fillable = ['url_sha256', 'url', 'final_url', 'site_name', 'title', 'description', 'image_path', 'status', 'error_code', 'fetched_at', 'expires_at'];

    protected $casts = ['fetched_at' => 'datetime', 'expires_at' => 'datetime'];

    public function messageLinks(): HasMany
    {
        return $this->hasMany(ChatMessageLink::class, 'link_preview_id');
    }
}
