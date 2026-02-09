<?php

namespace App\Services\Sites;

use App\Models\SiteCalendarEvent;
use App\Models\SiteCalendarEventException;
use Carbon\Carbon;

class SiteCalendarService
{
    /**
     * Get events for a date range, handling recurring events
     */
    public function getEventsForRange(
        ?array $siteIds,
        ?array $eventTypes,
        Carbon $start,
        Carbon $end,
        ?int $userId = null
    ): array {
        $query = SiteCalendarEvent::query()
            ->when($siteIds, fn($q) => $q->whereIn('site_id', $siteIds))
            ->when($eventTypes, fn($q) => $q->whereIn('event_type', $eventTypes))
            ->when($userId, fn($q) => $q->where(function($sq) use ($userId) {
                $sq->where('owner_user_id', $userId)
                   ->orWhereJsonContains('attendee_user_ids', (string) $userId);
            }))
            ->whereNull('recurrence_parent_id')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_at', [$start, $end])
                  ->orWhere(function ($sq) use ($end) {
                      $sq->whereNotNull('recurrence_rule')
                         ->where('start_at', '<=', $end);
                  });
            })
            ->with(['site:id,name,type', 'owner:id,name']);

        $events = $query->get();
        $occurrences = [];

        foreach ($events as $event) {
            if ($event->recurrence_rule) {
                $occurrences = array_merge(
                    $occurrences,
                    $this->expandRecurringEvent($event, $start, $end)
                );
            } else {
                $occurrences[] = $this->formatOccurrence($event);
            }
        }

        return $occurrences;
    }

    /**
     * Expand a recurring event into individual occurrences
     */
    private function expandRecurringEvent(
        SiteCalendarEvent $event,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        // Get exceptions for this event
        $exceptions = SiteCalendarEventException::where('parent_event_id', $event->id)
            ->whereBetween('exception_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->pluck('is_cancelled', 'exception_date')
            ->toArray();

        $occurrences = [];
        
        // Use PHP native DatePeriod for RRULE expansion (simplified)
        // For production, use a library like `spatie/icalendar-generator` or `eluceo/ical`
        $occurrencesData = $this->calculateOccurrences($event, $rangeStart, $rangeEnd);

        foreach ($occurrencesData as $occurrence) {
            $date = $occurrence['date']->format('Y-m-d');
            
            // Skip if exception says cancelled
            if (isset($exceptions[$date]) && $exceptions[$date]) {
                continue;
            }

            $occurrences[] = $this->formatOccurrence($event, $occurrence);
        }

        return $occurrences;
    }

    /**
     * Calculate occurrences from RRULE (simplified implementation)
     */
    private function calculateOccurrences(
        SiteCalendarEvent $event,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $occurrences = [];
        $start = $event->start_at->copy();
        
        // Parse simple RRULE patterns
        if (str_contains($event->recurrence_rule, 'FREQ=DAILY')) {
            $interval = $this->extractInterval($event->recurrence_rule) ?: 1;
            $current = $start->copy();
            
            while ($current <= $rangeEnd) {
                if ($current >= $rangeStart) {
                    $occurrences[] = ['date' => $current->copy()];
                }
                $current->addDays($interval);
            }
        } elseif (str_contains($event->recurrence_rule, 'FREQ=WEEKLY')) {
            $interval = $this->extractInterval($event->recurrence_rule) ?: 1;
            $current = $start->copy();
            
            while ($current <= $rangeEnd) {
                if ($current >= $rangeStart) {
                    $occurrences[] = ['date' => $current->copy()];
                }
                $current->addWeeks($interval);
            }
        } elseif (str_contains($event->recurrence_rule, 'FREQ=MONTHLY')) {
            $interval = $this->extractInterval($event->recurrence_rule) ?: 1;
            $current = $start->copy();
            
            while ($current <= $rangeEnd) {
                if ($current >= $rangeStart) {
                    $occurrences[] = ['date' => $current->copy()];
                }
                $current->addMonths($interval);
            }
        } else {
            // Single occurrence fallback
            if ($start >= $rangeStart && $start <= $rangeEnd) {
                $occurrences[] = ['date' => $start];
            }
        }

        return $occurrences;
    }

    private function extractInterval(string $rrule): ?int
    {
        if (preg_match('/INTERVAL=(\d+)/', $rrule, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Create exception for a recurring event occurrence
     */
    public function createException(
        int $parentEventId,
        string $exceptionDate,
        ?array $overriddenFields = null,
        bool $isCancelled = false
    ): SiteCalendarEventException {
        return SiteCalendarEventException::create([
            'parent_event_id' => $parentEventId,
            'exception_date' => $exceptionDate,
            'is_cancelled' => $isCancelled,
            'overridden_fields' => $overriddenFields,
        ]);
    }

    private function formatOccurrence(
        SiteCalendarEvent $event,
        ?array $occurrence = null
    ): array {
        $date = $occurrence['date'] ?? $event->start_at;
        
        return [
            'id' => $event->id,
            'is_exception' => $occurrence !== null,
            'title' => $event->title,
            'event_type' => $event->event_type,
            'start_at' => $date->toIso8601String(),
            'end_at' => $event->end_at?->toIso8601String(),
            'site' => $event->site,
            'owner' => $event->owner,
            'status' => $event->status,
            'approval_status' => $event->approval_status,
        ];
    }

    /**
     * Check for booking conflicts
     */
    public function hasConflict(
        int $siteId,
        Carbon $start,
        Carbon $end,
        ?int $excludeEventId = null
    ): bool {
        $query = SiteCalendarEvent::where('site_id', $siteId)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_at', [$start, $end])
                  ->orWhereBetween('end_at', [$start, $end])
                  ->orWhere(function ($sq) use ($start, $end) {
                      $sq->where('start_at', '<=', $start)
                         ->where('end_at', '>=', $end);
                  });
            })
            ->whereNotIn('status', ['cancelled', 'declined']);

        if ($excludeEventId) {
            $query->where('id', '!=', $excludeEventId);
        }

        return $query->exists();
    }
}
