<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpsMessage extends Model
{
    use HasFactory;
    use SoftDeletes;
    use WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'sender_type',
        'message_type',
        'content',
        'attachments',
        'is_read',
        'read_at',
        'is_pinned',
        'meta',
        'client_id',
        'shift_id',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_read' => 'boolean',
        'is_pinned' => 'boolean',
        'read_at' => 'datetime',
        'meta' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(OpsConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(OpsMessageReaction::class, 'message_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
