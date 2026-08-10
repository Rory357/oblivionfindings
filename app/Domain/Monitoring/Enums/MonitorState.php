<?php

namespace App\Domain\Monitoring\Enums;

enum MonitorState: string
{
    case Pending = 'pending';
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Stale = 'stale';
    case Suppressed = 'suppressed';
    case NotApplicable = 'not_applicable';

    public function isFailure(): bool
    {
        return in_array($this, [self::Degraded, self::Failed], true);
    }
}
