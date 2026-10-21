<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OpsConversation extends Model
{
    use HasFactory;
    use WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'title',
        'conversation_type',
        'client_id',
        'is_archived',
        'care_team_cover',
        'queue_status',
        'assigned_to',
        'chat_version',
        'is_managed_channel',
        'channel_site_id',
        'chat_created_by',
        'creation_mutation_id',
        'family_response_started_at',
        'family_response_message_id',
        'family_contact_kind',
        'family_contact_user_id',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'care_team_cover' => 'boolean',
        'chat_version' => 'integer',
        'is_managed_channel' => 'boolean',
        'family_response_started_at' => 'immutable_datetime',
        'family_response_message_id' => 'integer',
        'family_contact_user_id' => 'integer',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(OpsConversationParticipant::class, 'conversation_id');
    }

    /** Participants excluding care-team cover read-state rows. */
    public function members(): HasMany
    {
        return $this->participants()->where('role', '!=', OpsConversationParticipant::COVER_READER);
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
