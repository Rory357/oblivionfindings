<?php

namespace App\Domain\SecurityDevices\Management\Enums;

enum CommandStatus: string
{
    case Requested = 'requested';
    case AwaitingStepUp = 'awaiting_step_up';
    case AwaitingApproval = 'awaiting_approval';
    case AwaitingChange = 'awaiting_change';
    case Ready = 'ready';
    case Queued = 'queued';
    case Dispatching = 'dispatching';
    case Accepted = 'accepted';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Blocked = 'blocked';
    case Uncertain = 'uncertain';
    case Reconciling = 'reconciling';
    case Reconciled = 'reconciled';
    case Mismatch = 'mismatch';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Failed,
            self::Rejected,
            self::Expired,
            self::Cancelled,
            self::Blocked,
            self::Reconciled,
            self::Mismatch,
        ], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        return in_array($next, match ($this) {
            self::Requested => [
                self::AwaitingStepUp,
                self::AwaitingApproval,
                self::AwaitingChange,
                self::Ready,
                self::Expired,
                self::Cancelled,
                self::Blocked,
            ],
            self::AwaitingStepUp => [
                self::AwaitingApproval,
                self::AwaitingChange,
                self::Ready,
                self::Expired,
                self::Cancelled,
                self::Blocked,
            ],
            self::AwaitingApproval => [
                self::AwaitingStepUp,
                self::AwaitingChange,
                self::Ready,
                self::Rejected,
                self::Expired,
                self::Cancelled,
                self::Blocked,
            ],
            self::AwaitingChange => [self::Ready, self::Expired, self::Cancelled, self::Blocked],
            self::Ready => [self::Queued, self::Dispatching, self::Expired, self::Cancelled, self::Blocked],
            self::Queued => [self::Dispatching, self::Expired, self::Cancelled, self::Blocked],
            self::Dispatching => [
                self::Accepted,
                self::Running,
                self::Succeeded,
                self::Failed,
                self::Uncertain,
                self::Reconciling,
                self::Expired,
            ],
            self::Accepted => [
                self::Running,
                self::Succeeded,
                self::Failed,
                self::Uncertain,
                self::Reconciling,
                self::Expired,
            ],
            self::Running => [
                self::Succeeded,
                self::Failed,
                self::Uncertain,
                self::Reconciling,
                self::Expired,
            ],
            self::Succeeded => [self::Reconciling],
            self::Uncertain, self::Reconciling => [
                self::Reconciled,
                self::Mismatch,
                self::Uncertain,
            ],
            self::Failed,
            self::Rejected,
            self::Expired,
            self::Cancelled,
            self::Blocked,
            self::Reconciled,
            self::Mismatch => [],
        }, true);
    }
}
