<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\Client;
use App\Models\ClientMedicationStock;
use App\Models\MedicationReview;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Sites\Calendar\CalendarItem;
use App\Support\MedicationJourney;
use App\Support\WorkerClock;
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
        $actor = auth()->user();
        if (! $actor || ! $actor->canDo('medications.view')) {
            return [];
        }
        $approved = app(MedicationGovernanceScopeService::class)->readerSiteIds($actor, 'medications.view');
        $siteIds = array_values(array_intersect($siteIds, $approved));
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

        $readable = app(MedicationRecordAccess::class)->readableClientIds(auth()->user(),
            Client::query()->whereIn('site_id', $siteIds)->pluck('id'));
        $reviews = MedicationReview::query()
            ->whereIn('client_id', $readable)
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
                status: WorkerClock::daysUntil($due) < 0 ? 'overdue' : 'scheduled',
                ref: strtoupper((string) $review->review_type),
                site: $this->siteArray($review->client?->site),
                link: '/emar/reviews?'.http_build_query(array_filter([
                    'review' => $review->id, 'client_id' => $review->client_id, 'site_id' => $review->client?->site_id,
                    'return_to' => MedicationJourney::returnTo(request()->query('return_to')),
                ], fn ($value) => $value !== null), '', '&', PHP_QUERY_RFC3986),
            );
        }

        return $items;
    }

    /** @return CalendarItem[] */
    private function stockExpiryObligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        $items = [];

        $actor = auth()->user();
        $readable = app(MedicationRecordAccess::class)->readableClientIds($actor,
            Client::query()->whereIn('site_id', $siteIds)->pluck('id'));
        $stocks = ClientMedicationStock::query()
            ->whereHas('medication', function ($q) use ($actor, $readable): void {
                $q->whereIn('client_id', $readable);
                if (! $actor->canDo('medications.controlled.view')) {
                    $q->where('controlled_drug', false);
                }
            })
            ->where(fn ($q) => $q
                ->where(fn ($legacy) => $legacy->whereNull('lots_started_at')->where('on_hand', '>', 0)
                    ->whereBetween('expiry_date', [$start->toDateString(), $end->toDateString()]))
                ->orWhere(fn ($tracked) => $tracked->whereNotNull('lots_started_at')->whereHas('lots',
                    fn ($lots) => $lots->where('state', 'open')->where('quantity_remaining', '>', 0)
                        ->whereBetween('expiry_date', [$start->toDateString(), $end->toDateString()]))))
            ->whereHas('medication', fn ($q) => $q->where('active', true)->where('state', 'active')
                ->whereHas('client', fn ($c) => $c->whereIn('site_id', $siteIds)))
            ->with([
                'medication:id,name,client_id,controlled_drug',
                'medication.client:id,first_name,last_name,site_id',
                'medication.client.site:id,name,type',
                'lots' => fn ($lots) => $lots->where('state', 'open')->where('quantity_remaining', '>', 0)
                    ->whereBetween('expiry_date', [$start->toDateString(), $end->toDateString()]),
            ])
            ->get();

        foreach ($stocks as $stock) {
            $client = $stock->medication?->client;
            $clientName = trim(($client?->first_name ?? '').' '.($client?->last_name ?? ''));
            // Once pack evidence starts, scalar batch/expiry fields are legacy
            // snapshots. Each open, non-empty pack owns its expiry obligation.
            foreach ($stock->lots_started_at === null ? [null] : $stock->lots as $lot) {
                $due = $lot !== null ? $lot->expiry_date : $stock->expiry_date;
                if (! $due instanceof Carbon || ! $this->inRange($due, $start, $end)) {
                    continue;
                }
                $batch = $lot !== null ? $lot->batch_number : $stock->batch_number;
                $canUpdateStock = $actor->canDo('medications.stock.update');
                $context = array_filter([
                    'site_id' => $client?->site_id, 'client_id' => $client?->id,
                    'medication_id' => $stock->client_medication_id,
                    'lot_id' => $canUpdateStock ? $lot?->id : null,
                    'return_to' => MedicationJourney::returnTo(request()->query('return_to')),
                ], fn ($value) => $value !== null);
                $destination = $canUpdateStock ? '/emar/stock/packs' : '/emar/mar';
                if (! $canUpdateStock) {
                    $context['tab'] = 'medicines';
                }

                $items[] = new CalendarItem(
                    id: $lot !== null ? "medication-stock-lot-{$lot->id}" : "medication-stock-{$stock->id}",
                    source: 'medication',
                    group: 'auto',
                    title: ($stock->medication?->name ?? 'Medication').($clientName !== '' ? ' ('.$clientName.')' : '').' — Stock expires'
                        .($batch !== null ? ' · Batch '.$batch : ($lot !== null ? ' · Batch not printed' : '')),
                    start: $this->isoDate($due),
                    allDay: true,
                    status: $this->dueStatus($due, false),
                    ref: $batch,
                    site: $this->siteArray($client?->site),
                    link: $destination.'?'.http_build_query($context, '', '&', PHP_QUERY_RFC3986),
                    desc: $lot !== null ? $lot->quantity_remaining.' '.$stock->unit.' remaining in this pack.' : null,
                );
            }
        }

        return $items;
    }
}
