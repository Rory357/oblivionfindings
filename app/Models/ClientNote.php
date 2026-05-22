<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientNote extends Model implements EmitsToTimeline
{
    use AuditableChanges;

    protected $table = 'client_notes';

    protected $fillable = [
        'client_id',
        'shift_id',
        'user_id',
        'type',
        'subject',
        'goal',
        'body',
        'occurred_at',
        'visibility',
        'is_pinned',
        'is_flagged',
        'flagged_reason',
        'reviewed_at',
        'reviewed_by',
        'is_private',
        'attachments',
        'mood_rating',
        'organization_id',
        'category',
        'behaviour_tags',
        'concerns_flags',
        'follow_up_action',
        'follow_up_due_at',
        'follow_up_completed_at',
        'appears_on_timeline',
        'is_draft',
        'contact_person',
        'contact_relationship',
        'contact_method',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'is_pinned' => 'boolean',
        'is_flagged' => 'boolean',
        'is_private' => 'boolean',
        'reviewed_at' => 'datetime',
        'attachments' => 'array',
        'mood_rating' => 'integer',
        'behaviour_tags' => 'array',
        'concerns_flags' => 'array',
        'follow_up_due_at' => 'datetime',
        'follow_up_completed_at' => 'datetime',
        'appears_on_timeline' => 'boolean',
        'is_draft' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (ClientNote $note) {
            if ($note->is_draft) {
                $note->visibility = 'internal';
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeFlagged($query)
    {
        return $query->where('is_flagged', true);
    }

    public function scopeDailyNotes($query)
    {
        return $query->whereIn('type', ['daily_note', 'quick', 'progress_note', 'handover', 'note']);
    }

    public function scopeCommunication($query)
    {
        return $query->where('type', 'communication');
    }

    public function scopeReviewQueue($query)
    {
        return $query->where('is_flagged', true)->whereNull('reviewed_at');
    }

    public function scopeForUser($query, ?User $user)
    {
        if ($user?->organization_id) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('organization_id')->orWhere('organization_id', $user->organization_id);
            });
        }

        return $query;
    }

    public function scopeShiftLinked($query)
    {
        return $query->whereNotNull('shift_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toTimelineEvent(): ?array
    {
        if ($this->is_draft || $this->appears_on_timeline === false) {
            return null;
        }

        $this->loadMissing('client');

        $type = $this->type ?: 'note';
        $clientName = $this->client
            ? trim(($this->client->first_name ?? '').' '.($this->client->last_name ?? ''))
            : 'Client';

        return [
            'type' => $type,
            'occurred_at' => $this->occurred_at ?? $this->created_at ?? now(),
            'actor_user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'shift_id' => $this->shift_id,
            'site_id' => $this->client?->site_id,
            'subject' => $this->subject ?: ucfirst(str_replace('_', ' ', $type)).' for '.$clientName,
            'body' => $this->body,
            'meta' => array_filter([
                'note_id' => $this->id,
                'goal' => $this->goal ?? null,
                'category' => $this->category ?? null,
                'behaviour_tags' => $this->behaviour_tags ?? null,
                'concerns_flags' => $this->concerns_flags ?? null,
                'follow_up_action' => $this->follow_up_action ?? null,
                'contact_person' => $this->contact_person ?? null,
                'contact_relationship' => $this->contact_relationship ?? null,
                'contact_method' => $this->contact_method ?? null,
            ], fn ($value) => $value !== null && $value !== '' && $value !== []),
            'visibility' => $this->visibility ?? 'internal',
            'is_pinned' => (bool) $this->is_pinned,
            'created_by' => $this->user_id,
        ];
    }
}
