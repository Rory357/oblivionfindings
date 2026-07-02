<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\ClientMedicationStock;
use App\Models\MedicationReview;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * eMAR obligations — scheduled medication reviews and medication stock expiry
 * dates surface on the Site Calendar without re-entry. The eMAR pages
 * (/emar/reviews, /emar/stock) stay the single write surfaces.
 */
class MedicationObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'medication';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        return [
            ...$this->reviewObligations($siteIds, $start, $end),
            ...$this->stockExpiryObligations($siteIds, $start, $end),
        ];
    }

    /** @return CalendarItem[] */
    private function reviewObligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        $items = [];

        $reviews = MedicationReview::query()
            ->whereIn('status', ['scheduled', 'overdue'])
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('client', fn ($q) => $q->whereIn('site_id', $siteIds))
            ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name,type'])
            ->get();

        foreach ($reviews as $review) {
            $due = $review->scheduled_date;
            if (! $due instanceof Carbon || ! $this->inRange($due, $start, $end)) {
                continue;
            }

            $clientName = trim(($review->client?->first_name ?? '').' '.($review->client?->last_name ?? ''));

            $items[] = new CalendarItem(
                id: "medication-review-{$review->id}",
                source: 'medication',
                group: 'auto',
                title: ($clientName !== '' ? $clientName.' — ' : '').'Medication review due',
                start: $this->isoDate($due),
                allDay: true,
                status: $this->dueStatus($due, false),
                ref: strtoupper((string) $review->review_type),
                site: $this->siteArray($review->client?->site),
                link: '/emar/reviews',
            );
        }

        return $items;
    }

    /** @return CalendarItem[] */
    private function stockExpiryObligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        $items = [];

        $stocks = ClientMedicationStock::query()
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('medication', fn ($q) => $q->where('active', true)->where('state', 'active')
                ->whereHas('client', fn ($c) => $c->whereIn('site_id', $siteIds)))
            ->with([
                'medication:id,name,client_id',
                'medication.client:id,first_name,last_name,site_id',
                'medication.client.site:id,name,type',
            ])
            ->get();

        foreach ($stocks as $stock) {
            $due = $stock->expiry_date instanceof Carbon ? $stock->expiry_date : Carbon::parse($stock->expiry_date);
            if (! $this->inRange($due, $start, $end)) {
                continue;
            }

            $client = $stock->medication?->client;
            $clientName = trim(($client?->first_name ?? '').' '.($client?->last_name ?? ''));

            $items[] = new CalendarItem(
                id: "medication-stock-{$stock->id}",
                source: 'medication',
                group: 'auto',
                title: ($stock->medication?->name ?? 'Medication').($clientName !== '' ? ' ('.$clientName.')' : '').' — Stock expires',
                start: $this->isoDate($due),
                allDay: true,
                status: $this->dueStatus($due, false),
                ref: $stock->batch_number,
                site: $this->siteArray($client?->site),
                link: '/emar/stock',
            );
        }

        return $items;
    }
}
