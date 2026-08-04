<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientRoutine extends Model implements EmitsToTimeline
{
    use AuditableChanges, SoftDeletes, WritesLegacyOrganizationStorageContext;

    public const BLOCKS = [
        'morning' => 10,
        'day' => 20,
        'evening' => 30,
        'overnight' => 40,
        'preferences' => 50,
        'triggers' => 60,
        'calming' => 70,
        'what_works' => 80,
        'avoid' => 90,
    ];

    protected $fillable = [
        'client_id',
        'time_block',
        'body',
        'display_order',
        'updated_by',
    ];

    protected $casts = [
        'display_order' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function toTimelineEvent(): array
    {
        $this->loadMissing('client');

        return [
            'type' => 'routine_updated',
            'occurred_at' => $this->updated_at ?? $this->created_at ?? now(),
            'actor_user_id' => $this->updated_by,
            'client_id' => $this->client_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Routine updated: '.ucfirst(str_replace('_', ' ', $this->time_block)),
            'body' => $this->body,
            'meta' => [
                'time_block' => $this->time_block,
            ],
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $this->updated_by,
        ];
    }
}
