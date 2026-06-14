<?php

namespace App\Domain\Finance\Services\Calendar;

use App\Services\Sites\Calendar\CalendarItem;

/**
 * Normalised finance obligation — the single shape returned by the finance
 * calendar feed. Each item is an all-day marker on the date a money obligation
 * falls due (an invoice/bill due date, a scheduled payment run, a GST filing
 * deadline). Items are read-only and deep-link back to their source record;
 * they are never persisted as calendar events.
 *
 * Mirrors the Sites calendar's {@see CalendarItem}
 * pattern so the same React calendar wrapper can consume it, but carries
 * finance-specific fields (amount, direction, counterparty) instead of the
 * Sites room/attendee/series shape.
 */
class FinanceCalendarItem
{
    /**
     * @param  string  $id  Unique id, e.g. "invoice-12" or "gst-3"
     * @param  string  $source  Source key: invoice_due|bill_due|payment_run|gst_due
     * @param  string  $title  Human label shown on the calendar
     * @param  string  $start  ISO date (Y-m-d) the obligation falls due
     * @param  string  $status  due|overdue|paid|scheduled|processed|filed|draft
     * @param  float|null  $amount  Money amount in NZD, or null
     * @param  string|null  $direction  'inflow' (money in) | 'outflow' (money out)
     * @param  string|null  $ref  Human reference, e.g. INV-00001
     * @param  string|null  $counterparty  Client/vendor name, or null
     * @param  string|null  $link  Deep link to the source record
     * @param  array  $meta  Source-specific extras
     */
    public function __construct(
        public string $id,
        public string $source,
        public string $title,
        public string $start,
        public string $status = 'due',
        public ?float $amount = null,
        public ?string $direction = null,
        public ?string $ref = null,
        public ?string $counterparty = null,
        public ?string $link = null,
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'group' => 'finance',
            'title' => $this->title,
            'start' => $this->start,
            'end' => null,
            'allDay' => true,
            'status' => $this->status,
            'amount' => $this->amount,
            'direction' => $this->direction,
            'ref' => $this->ref,
            'counterparty' => $this->counterparty,
            'link' => $this->link,
            'meta' => $this->meta,
        ];
    }
}
