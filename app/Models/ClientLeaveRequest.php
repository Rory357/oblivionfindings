<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use App\Services\Medication\DoseSlots\DoseAwaySources;
use App\Services\Medication\OverdueDoseAlerts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientLeaveRequest extends Model implements EmitsToTimeline
{
    use AuditableChanges, SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'client_id',
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
        'departed_at', 'departed_by', 'returned_at', 'returned_by',
        'withdrawn_at', 'withdrawn_by', 'withdrawal_reason', 'version',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'approved_at' => 'datetime',
        'departed_at' => 'datetime',
        'returned_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        // Actual absence changes are read live; recorded dose outcomes still win.
        $resync = static function (self $leave): void {
            if (DoseAwaySources::leaveCounts()) {
                OverdueDoseAlerts::queueAfterCommit((int) $leave->client_id);
            }
        };
        static::saved($resync);
        static::deleted($resync);
    }

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

    public function transitions(): MorphMany
    {
        return $this->morphMany(TimelineEvent::class, 'source')->whereIn('type', [
            'leave_transition_created', 'leave_transition_approve', 'leave_transition_decline',
            'leave_transition_depart', 'leave_transition_return', 'leave_transition_withdraw',
        ])->orderBy('id');
    }

    /** State possibilities only; the reader also gates the actor's Client update authority. */
    public function allowedActions(bool $canUpdate = true): array
    {
        if (! $canUpdate || $this->withdrawn_at !== null || in_array($this->status, ['completed', 'cancelled', 'declined'], true)) {
            return [];
        }
        if ($this->departed_at !== null) {
            return $this->returned_at === null ? ['return'] : [];
        }
        if ($this->status === 'requested') {
            return ['approve', 'decline', 'withdraw'];
        }

        return $this->status === 'approved'
            ? ($this->approved_at !== null && $this->approved_by !== null ? ['depart', 'withdraw'] : ['approve', 'withdraw'])
            : [];
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
