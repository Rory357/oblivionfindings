<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ItReplyTemplate extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'review_due_at' => 'date',
        'is_active' => 'boolean',
        'lock_version' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ItReplyTemplateVersion::class, 'template_id');
    }
}
