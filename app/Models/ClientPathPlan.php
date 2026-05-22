<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Person-centred PATH (Planning Alternative Tomorrows with Hope) plan
 * for a supported-living client. One row per client. Feeds the Goals/PATH
 * tab and the Actions/Reviews aggregator (via next_review_at).
 */
class ClientPathPlan extends Model implements EmitsToTimeline
{
    use AuditableChanges, SoftDeletes;

    protected $fillable = [
        'client_id',
        'organization_id',
        'dream',
        'north_star',
        'strengths',
        'action_steps',
        'trusted_people',
        'independence_goals',
        'community',
        'meaningful_outcomes',
        'plan_date',
        'next_review_at',
        'facilitator_id',
        'updated_by',
    ];

    protected $casts = [
        'strengths' => 'array',
        'action_steps' => 'array',
        'trusted_people' => 'array',
        'independence_goals' => 'array',
        'plan_date' => 'date',
        'next_review_at' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineEvent(): ?array
    {
        if (! $this->plan_date && ! $this->dream) {
            return null;
        }

        $this->loadMissing('client');

        return [
            'type' => 'path_plan_updated',
            'occurred_at' => $this->updated_at ?? now(),
            'actor_user_id' => $this->updated_by ?? $this->facilitator_id,
            'client_id' => $this->client_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'PATH plan updated'
                .($this->dream ? ': '.\Illuminate\Support\Str::limit($this->dream, 60) : ''),
            'body' => $this->meaningful_outcomes ?? $this->dream,
            'meta' => array_filter([
                'plan_date' => $this->plan_date?->toDateString(),
                'next_review_at' => $this->next_review_at?->toDateString(),
                'strengths_count' => is_array($this->strengths) ? count($this->strengths) : 0,
                'action_steps_count' => is_array($this->action_steps) ? count($this->action_steps) : 0,
            ], fn ($value) => $value !== null && $value !== '' && $value !== 0),
            'visibility' => 'internal',
            'is_pinned' => true, // PATH plans are pinned by default — they outlive normal events
            'created_by' => $this->updated_by ?? $this->facilitator_id,
        ];
    }
}
