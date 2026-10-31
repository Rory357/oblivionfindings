<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Oblivion's own sticker set and the organisation's uploaded library. There is no outside GIF service. */
class ChatSticker extends Model
{
    protected $fillable = ['set_key', 'name', 'storage_path', 'mime_type', 'sha256', 'width', 'height', 'created_by', 'archived_at'];

    protected $casts = ['width' => 'integer', 'height' => 'integer', 'archived_at' => 'datetime'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OpsMessage::class, 'sticker_id');
    }
}
