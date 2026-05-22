<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProgressNote extends Model implements EmitsToTimeline
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'client_id',
        'shift_id',
        'care_plan_goal_id',
        'author_id',
        'note_type',
        'content',
        'mood_rating',
        'emotions',
        'is_flagged',
        'flagged_reason',
        'ai_summary',
        'visibility',
    ];

    protected $casts = [
        'is_flagged' => 'boolean',
        'emotions' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function goal()
    {
        return $this->belongsTo(CarePlanGoal::class, 'care_plan_goal_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeFlagged($query)
    {
        return $query->where('is_flagged', true);
    }

    public function scopeForFamily($query)
    {
        return $query->where('visibility', 'include_family');
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineEvent(): array
    {
        $this->loadMissing('client');

        $emotionText = collect($this->emotions ?? [])->join(', ');

        return [
            'type' => 'progress_note',
            'occurred_at' => $this->created_at ?? now(),
            'actor_user_id' => $this->author_id,
            'client_id' => $this->client_id,
            'shift_id' => $this->shift_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Progress note: '.ucfirst(str_replace('_', ' ', $this->note_type)).($emotionText ? ' ('.$emotionText.')' : ''),
            'body' => str($this->content)->limit(200)->toString(),
            'meta' => array_filter([
                'note_type' => $this->note_type,
                'emotions' => $this->emotions,
            ], fn ($value) => $value !== null && $value !== []),
            'visibility' => $this->visibility === 'include_family' ? 'portal' : 'internal',
            'is_pinned' => false,
            'created_by' => $this->author_id,
        ];
    }
}
