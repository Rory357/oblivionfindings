<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientLeaveRequest extends Model implements EmitsToTimeline
{
    use AuditableChanges, SoftDeletes;

    protected $fillable = [
        'client_id',
        'organization_id',
        'starts_on',
        'ends_on',
        'destination',
        'support_required',
        'risks_and_mitigations',
        'emergency_contact',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'approval_notes',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'approved_at' => 'datetime',
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
            'type' => 'leave_request',
            'occurred_at' => $this->approved_at ?? $this->created_at ?? now(),
            'actor_user_id' => $this->approved_by ?? $this->requested_by,
            'client_id' => $this->client_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Leave: '.($this->destination ?? 'unspecified destination'),
            'body' => trim(($this->support_required ?? '')."\n".($this->approval_notes ?? '')),
            'meta' => array_filter([
                'starts_on' => $this->starts_on?->toDateString(),
                'ends_on' => $this->ends_on?->toDateString(),
                'status' => $this->status,
                'destination' => $this->destination,
            ], fn ($value) => $value !== null && $value !== ''),
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $this->requested_by,
        ];
    }
}
