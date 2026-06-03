<?php

namespace App\Services\Sites\Calendar;

use Illuminate\Support\Carbon;

/**
 * Builds an RFC-5545 VCALENDAR from already-expanded {@see CalendarItem}s for the
 * personal subscribe feed. Items are concrete occurrences (the aggregator has
 * already expanded recurring series), so no RRULE is emitted.
 */
class IcsFeedBuilder
{
    /**
     * @param  CalendarItem[]  $items
     */
    public function build(array $items, string $calendarName = 'Site Calendar'): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Oblivion Findings//Site Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($calendarName),
        ];

        foreach ($items as $item) {
            $vevent = $this->vevent($item);
            if ($vevent !== null) {
                $lines[] = $vevent;
            }
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    private function vevent(CalendarItem $item): ?string
    {
        if (! $item->start) {
            return null;
        }

        $start = Carbon::parse($item->start);
        $end = $item->end ? Carbon::parse($item->end) : null;

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$item->id.'-'.$item->source.'@oblivionfindings.calendar',
            'DTSTAMP:'.$this->stamp(Carbon::now()),
        ];

        if ($item->allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:'.$start->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$this->stamp($start);
            if ($end) {
                $lines[] = 'DTEND:'.$this->stamp($end);
            }
        }

        $lines[] = 'SUMMARY:'.$this->escape($item->title);

        if ($item->room) {
            $siteName = $item->site['name'] ?? '';
            $lines[] = 'LOCATION:'.$this->escape(trim($item->room.' · '.$siteName, ' ·'));
        }
        if ($item->desc) {
            $lines[] = 'DESCRIPTION:'.$this->escape($item->desc);
        }
        if ($item->status) {
            $lines[] = 'STATUS:'.($item->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED');
        }

        $lines[] = 'END:VEVENT';

        return implode("\r\n", $lines);
    }

    private function stamp(Carbon $dt): string
    {
        return $dt->clone()->utc()->format('Ymd\THis\Z');
    }

    private function escape(?string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\\;', '\\,', '\\n'],
            (string) $value,
        );
    }
}
