<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageLink extends Model
{
    protected $fillable = ['message_id', 'link_preview_id', 'position', 'hidden'];

    protected $casts = ['position' => 'integer', 'hidden' => 'boolean'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(OpsMessage::class, 'message_id');
    }

    public function preview(): BelongsTo
    {
        return $this->belongsTo(ChatLinkPreview::class, 'link_preview_id');
    }
}
