<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A sign-off request on a helpdesk ticket (§P-S3). Certain categories need a
 * manager's approval before an agent may resolve/fulfil; this row is the
 * decision log — one live (pending/approved) request at a time, kept for audit.
 */
class ItTicketApproval extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const STATUSES = ['pending', 'approved', 'rejected', 'expired', 'cancelled'];

    protected $fillable = [
        'it_ticket_id',
        'requested_by',
        'approver_id',
        'status',
        'reason',
        'decided_at',
        'request_reason', 'request_reason_recorded_at',
        'decision_reason', 'decision_reason_recorded_at',
        'primary_approver_user_id', 'cover_approver_user_id', 'assignment_recorded_at',
        'expires_at', 'remind_at', 'reminder_prepared_at', 'decision_authority',
        'reminder_last_checked_at',
        'expired_at', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'request_reason_recorded_at' => 'datetime',
        'decision_reason_recorded_at' => 'datetime',
        'primary_approver_user_id' => 'integer', 'cover_approver_user_id' => 'integer',
        'assignment_recorded_at' => 'datetime', 'expires_at' => 'datetime',
        'remind_at' => 'datetime', 'reminder_prepared_at' => 'datetime',
        'reminder_last_checked_at' => 'datetime',
        'expired_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $approval): void {
            if ($approval->isDirty(['it_ticket_id', 'requested_by', 'reason', 'request_reason', 'request_reason_recorded_at', 'created_at',
                'primary_approver_user_id', 'cover_approver_user_id', 'assignment_recorded_at', 'expires_at', 'remind_at'])
                || ($approval->getRawOriginal('status') !== 'pending'
                    && $approval->isDirty(['status', 'approver_id', 'decided_at', 'decision_reason', 'decision_reason_recorded_at',
                        'decision_authority', 'expired_at', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason', 'reminder_prepared_at', 'reminder_last_checked_at']))
                || ($approval->getRawOriginal('reminder_prepared_at') !== null && $approval->isDirty('reminder_prepared_at'))) {
                throw new LogicException('Approval request and terminal decision evidence is immutable.');
            }
        });
        static::deleting(function (): void {
            throw new LogicException('Approval generations must be preserved.');
        });
    }

    public function isPastDeadline(): bool
    {
        return $this->status === 'pending' && $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now());
    }

    public function effectiveStatus(): string
    {
        return $this->isPastDeadline() ? 'expired' : $this->status;
    }

    public function primaryApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_approver_user_id');
    }

    public function coverApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cover_approver_user_id');
    }

    /** Preserve already-served clients without overwriting the stored legacy text. */
    public function getReasonAttribute(?string $value): ?string
    {
        return $this->attributes['decision_reason'] ?? $this->attributes['request_reason'] ?? $value;
    }

    /** Private evidence; callers must authorize current work access before projection. */
    public function reasonEvidence(): array
    {
        return [
            'request' => ['value' => $this->request_reason,
                'provenance' => $this->request_reason_recorded_at ? 'recorded_at_request' : 'legacy_unattributed',
                'recorded_at' => $this->request_reason_recorded_at?->toIso8601String()],
            'decision' => ['value' => $this->decision_reason,
                'provenance' => $this->decision_reason_recorded_at ? 'recorded_at_decision' : (in_array($this->status, ['pending', 'expired', 'cancelled'], true) ? 'not_decided' : 'legacy_unattributed'),
                'recorded_at' => $this->decision_reason_recorded_at?->toIso8601String()],
            'legacy_reason' => $this->request_reason_recorded_at === null ? $this->getRawOriginal('reason') : null,
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'it_ticket_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
