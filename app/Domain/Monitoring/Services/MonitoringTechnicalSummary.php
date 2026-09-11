<?php

namespace App\Domain\Monitoring\Services;

use App\Models\ItTicket;

/** Cross-module wording never includes a provider's free-text diagnostic payload. */
final class MonitoringTechnicalSummary
{
    public static function observation(?string $eventType): string
    {
        return match ($eventType) {
            'offline' => 'Monitoring confirmed an infrastructure outage. Technical verification is required.',
            'online' => 'Monitoring reported recovery. Technical verification is still required.',
            default => 'Monitoring recorded technical evidence. Review the authorised source record.',
        };
    }

    public static function activity(string $eventType): string
    {
        return match ($eventType) {
            'created_from_monitoring' => self::observation('offline'),
            'monitoring_recovered' => self::observation('online'),
            default => 'Additional monitoring evidence was recorded. Technical verification is required.',
        };
    }

    /** Conceal a historical copied diagnostic without rewriting evidence or later human edits. */
    public static function ticketDescription(ItTicket $ticket): ?string
    {
        $description = $ticket->description;
        if ($ticket->source !== 'system' || ! is_string($description)) {
            return $description;
        }

        $creation = $ticket->events()->where('type', 'created_from_monitoring')->oldest('id')->first(['payload']);
        $originalMessage = data_get($creation?->payload, 'message');

        return is_string($originalMessage) && trim($originalMessage) !== ''
            && trim($description) === trim($originalMessage)
                ? self::observation('offline')
                : $description;
    }
}
