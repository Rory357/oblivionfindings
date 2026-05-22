<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientBowelEntry extends Model implements EmitsToTimeline
{
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'organization_id',
        'occurred_at',
        'bristol_type',
        'volume',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'bristol_type' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineEvent(): array
    {
        $this->loadMissing('client');

        return [
            'type' => 'health_bowel',
            'occurred_at' => $this->occurred_at ?? $this->created_at ?? now(),
            'actor_user_id' => $this->recorded_by,
            'client_id' => $this->client_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Bowel chart entry',
            'body' => $this->notes,
            'meta' => [
                'bristol_type' => $this->bristol_type,
                'volume' => $this->volume,
            ],
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $this->recorded_by,
        ];
    }
}
