<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'pinned_by',
        'pinned_at',
        'meta',
        'client_id',
        'shift_id',
        'mutation_id',
        'reply_to_id',
        'edited_at',
        'body_document',
        'priority',
        'confirm_requested',
        'thread_root_id',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_read' => 'boolean',
        'is_pinned' => 'boolean',
        'pinned_at' => 'datetime',
        'read_at' => 'datetime',
        'meta' => 'array',
        'edited_at' => 'datetime',
        // Always a ChatDocument::normalise() result; content is the plain-text copy written from it.
        'body_document' => 'array',
        'confirm_requested' => 'boolean',
        // Ids only, never names.
        'system_params' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(OpsConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(OpsMessageReaction::class, 'message_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** The message this one's thread hangs from. reply_to_id stays the quoted reply. */
    public function threadRoot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'thread_root_id');
    }

    /** Access to the source is rechecked on every view; nothing of it is stored here. */
    public function sharedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'shared_from_message_id');
    }

    public function poll(): HasOne
    {
        return $this->hasOne(ChatPoll::class, 'message_id');
    }

    public function sticker(): BelongsTo
    {
        return $this->belongsTo(ChatSticker::class, 'sticker_id');
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(ChatCall::class, 'call_id');
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(ChatMessageConfirmation::class, 'message_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(ChatMessageCard::class, 'message_id')->orderBy('position');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ChatMessageLink::class, 'message_id')->orderBy('position');
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ChatScheduledMessage::class, 'scheduled_message_id');
    }
}
