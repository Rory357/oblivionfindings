<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OpsConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'title',
        'conversation_type',
        'client_id',
        'is_archived',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(OpsConversationParticipant::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OpsMessage::class, 'conversation_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(OpsMessage::class, 'conversation_id')->latestOfMany();
    }
}
