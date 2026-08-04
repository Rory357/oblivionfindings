<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientNote extends Model implements EmitsToTimeline
{
    use AuditableChanges;
    use SoftDeletes;
    use WritesLegacyOrganizationStorageContext;

    protected $table = 'client_notes';

    protected $fillable = [
        'legacy_progress_note_id',
        'client_id',
        'shift_id',
        'care_plan_goal_id',
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
        'ai_summary',
        'reviewed_at',
        'reviewed_by',
        'edited_at',
        'edited_by',
        'is_private',
        'attachments',
        'mood_rating',
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
        'legacy_progress_note_id' => 'integer',
        'care_plan_goal_id' => 'integer',
        'occurred_at' => 'datetime',
        'is_pinned' => 'boolean',
        'is_flagged' => 'boolean',
        'is_private' => 'boolean',
        'reviewed_at' => 'datetime',
        'edited_at' => 'datetime',
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

    public function carePlanGoal(): BelongsTo
    {
        return $this->belongsTo(CarePlanGoal::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
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
        return $query
            ->dailyNotes()
            ->submitted()
            ->where('is_flagged', true)
            ->whereNull('reviewed_at');
    }

    public function scopeSubmitted($query)
    {
        return $query->where(function ($submitted) {
            $submitted->whereNull('is_draft')->orWhere('is_draft', false);
        });
    }

    public function scopeForUser($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function ($visibility) use ($user) {
            $visibility
                ->where(function ($submitted) {
                    $submitted->whereNull('is_draft')->orWhere('is_draft', false);
                })
                ->orWhere(function ($draft) use ($user) {
                    $draft
                        ->where('is_draft', true)
                        ->where('user_id', $user?->id ?? 0);
                });
        });

        if (! $user->canDo('progress_notes.review')) {
            $query->where(function ($privacy) use ($user) {
                $privacy
                    ->whereNull('is_private')
                    ->orWhere('is_private', false)
                    ->orWhere('user_id', $user->id);
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
