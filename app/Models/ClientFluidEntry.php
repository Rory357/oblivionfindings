<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientFluidEntry extends Model implements EmitsToTimeline
{
    use AuditableChanges, WritesLegacyOrganizationStorageContext;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'occurred_at',
        'direction',
        'fluid_type',
        'volume_ml',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'volume_ml' => 'integer',
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

        $direction = $this->direction === 'out' ? 'output' : 'intake';

        return [
            'type' => 'health_fluid',
            'occurred_at' => $this->occurred_at ?? $this->created_at ?? now(),
            'actor_user_id' => $this->recorded_by,
            'client_id' => $this->client_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Fluid '.$direction.': '.$this->volume_ml.' ml',
            'body' => $this->notes,
            'meta' => [
                'direction' => $this->direction,
                'fluid_type' => $this->fluid_type,
                'volume_ml' => $this->volume_ml,
            ],
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $this->recorded_by,
        ];
    }
}
