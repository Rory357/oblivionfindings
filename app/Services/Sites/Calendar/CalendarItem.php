<?php

namespace App\Services\Sites\Calendar;

/**
 * Normalised calendar entry — the single shape returned by the events feed,
 * whether the row is a manually-created SiteCalendarEvent occurrence
 * (group: manual, editable) or an auto-derived obligation pulled from another
 * Sites module (group: auto, read-only, deep-links back to its source record).
 *
 * Mirrors the prototype's occurrence object (cal-recur.js makeOcc) so the React
 * port can consume it directly.
 */
class CalendarItem
{
    /**
     * @param  string       $id        Unique occurrence id, e.g. "inspection-12" or "event-45@2026-05-31"
     * @param  string       $source    Obligation source key, or "event" for manual entries
     * @param  string       $group     "manual" | "auto"
     * @param  string|null  $start     ISO-8601 datetime
     * @param  string|null  $end       ISO-8601 datetime, or null
     * @param  array|null   $owner     ['id' => int, 'name' => string]
     * @param  array|null   $site      ['id' => int, 'name' => string, 'type' => string]
     * @param  array|null   $recurrence {freq, interval, count?, until?} for manual recurring series
     * @param  array        $reminders Minutes-before list for manual entries
     */
    public function __construct(
        public string $id,
        public string $source,
        public string $group,
        public string $title,
        public ?string $start,
        public ?string $end = null,
        public bool $allDay = false,
        public string $status = 'scheduled',
        public ?array $owner = null,
        public ?string $room = null,
        public ?string $ref = null,
        public ?array $site = null,
        public ?string $link = null,
        public bool $editable = false,
        public ?string $eventType = null,
        public ?string $approvalStatus = null,
        public ?string $desc = null,
        public ?string $priority = null,
        public ?array $recurrence = null,
        public array $reminders = [],
        public array $attendeeIds = [],
        public ?int $seriesId = null,
        public bool $isOccurrence = false,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'group' => $this->group,
            'title' => $this->title,
            'start' => $this->start,
            'end' => $this->end,
            'allDay' => $this->allDay,
            'status' => $this->status,
            'owner' => $this->owner,
            'room' => $this->room,
            'ref' => $this->ref,
            'site' => $this->site,
            'link' => $this->link,
            'editable' => $this->editable,
            'eventType' => $this->eventType,
            'approvalStatus' => $this->approvalStatus,
            'desc' => $this->desc,
            'priority' => $this->priority,
            'recurrence' => $this->recurrence,
            'reminders' => $this->reminders,
            'attendeeIds' => $this->attendeeIds,
            'seriesId' => $this->seriesId,
            'isOccurrence' => $this->isOccurrence,
        ];
    }
}
