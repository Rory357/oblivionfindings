<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientSeizureEntry extends Model implements EmitsToTimeline
{
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'organization_id',
        'occurred_at',
        'duration_seconds',
        'seizure_type',
        'trigger',
        'response_taken',
        'recovery_notes',
        'escalated',
        'follow_up_action',
        'recorded_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'duration_seconds' => 'integer',
        'escalated' => 'boolean',
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

        $duration = $this->duration_seconds
            ? sprintf('%d min %d sec', intdiv($this->duration_seconds, 60), $this->duration_seconds % 60)
            : 'duration not recorded';

        return [
            'type' => ($this->escalated || ($this->duration_seconds ?? 0) > 300) ? 'status_critical' : 'health_seizure',
            'occurred_at' => $this->occurred_at ?? $this->created_at ?? now(),
            'actor_user_id' => $this->recorded_by,
            'client_id' => $this->client_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Seizure event: '.$duration,
            'body' => $this->response_taken ?: $this->recovery_notes,
            'meta' => [
                'duration_seconds' => $this->duration_seconds,
                'seizure_type' => $this->seizure_type,
                'trigger' => $this->trigger,
                'escalated' => $this->escalated,
                'follow_up_action' => $this->follow_up_action,
            ],
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $this->recorded_by,
        ];
    }
}
