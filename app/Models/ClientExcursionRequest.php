<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientExcursionRequest extends Model implements EmitsToTimeline
{
    use AuditableChanges, SoftDeletes;

    protected $fillable = [
        'client_id',
        'organization_id',
        'starts_at',
        'ends_at',
        'destination',
        'activity_description',
        'transport_method',
        'staff_assignments',
        'risk_assessment',
        'outcome_notes',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'approval_notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'approved_at' => 'datetime',
        'staff_assignments' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function toTimelineEvent(): ?array
    {
        $this->loadMissing('client');

        return [
            'type' => 'excursion',
            'occurred_at' => $this->starts_at ?? $this->created_at ?? now(),
            'actor_user_id' => $this->approved_by ?? $this->requested_by,
            'client_id' => $this->client_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Excursion: '.($this->destination ?? 'unspecified activity'),
            'body' => trim(($this->activity_description ?? '')."\n".($this->outcome_notes ?? '')),
            'meta' => array_filter([
                'starts_at' => $this->starts_at?->toISOString(),
                'ends_at' => $this->ends_at?->toISOString(),
                'transport_method' => $this->transport_method,
                'status' => $this->status,
            ], fn ($value) => $value !== null && $value !== ''),
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $this->requested_by,
        ];
    }
}
