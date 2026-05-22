<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;

class ClientAssessment extends Model implements EmitsToTimeline
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'created_by_user_id',
        'type',
        'score',
        'notes',
        'assessed_at',
        'next_review_at',
    ];

    protected $casts = [
        'assessed_at' => 'date',
        'next_review_at' => 'date',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toTimelineEvent(): ?array
    {
        if (! $this->next_review_at) {
            return null;
        }

        $this->loadMissing('client');

        return [
            'type' => 'assessment_review_due',
            'occurred_at' => $this->next_review_at,
            'actor_user_id' => $this->created_by_user_id,
            'client_id' => $this->client_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Assessment review due: '.ucfirst(str_replace('_', ' ', (string) $this->type)),
            'body' => $this->notes,
            'meta' => array_filter([
                'assessment_type' => $this->type,
                'score' => $this->score,
                'assessed_at' => $this->assessed_at?->toDateString(),
            ], fn ($value) => $value !== null && $value !== ''),
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $this->created_by_user_id,
        ];
    }
}
