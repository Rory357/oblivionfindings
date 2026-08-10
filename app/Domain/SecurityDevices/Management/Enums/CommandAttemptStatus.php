<?php

namespace App\Domain\SecurityDevices\Management\Enums;

enum CommandAttemptStatus: string
{
    case Dispatching = 'dispatching';
    case Accepted = 'accepted';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Uncertain = 'uncertain';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Failed,
            self::Expired,
            self::Cancelled,
            self::Uncertain,
        ], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        return in_array($next, match ($this) {
            self::Dispatching => [
                self::Accepted,
                self::Running,
                self::Succeeded,
                self::Failed,
                self::Expired,
                self::Cancelled,
                self::Uncertain,
            ],
            self::Accepted => [
                self::Running,
                self::Succeeded,
                self::Failed,
                self::Expired,
                self::Cancelled,
                self::Uncertain,
            ],
            self::Running => [
                self::Succeeded,
                self::Failed,
                self::Expired,
                self::Cancelled,
                self::Uncertain,
            ],
            self::Succeeded,
            self::Failed,
            self::Expired,
            self::Cancelled,
            self::Uncertain => [],
        }, true);
    }
}
