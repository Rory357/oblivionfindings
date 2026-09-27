<?php

namespace App\Services\Reporting;

use App\Domain\Finance\Models\FinBill;
use App\Models\Asset;
use App\Models\AssetCustodyMovement;
use App\Models\FleetFuelLog;
use App\Models\FleetObligationReminder;
use App\Models\FleetTrip;
use App\Services\Assets\AssetStocktakeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class FleetSupplementalReports
{
    public function read(string $source, array $context, CarbonImmutable $from, CarbonImmutable $to, array &$watermark, ReportSourceEvidence $provenance): array
    {
        $assets = Asset::whereIn('id', $context['asset_ids'])->with('site', 'homeSite')->get()->keyBy('id');
        foreach ($assets as $asset) {
            $provenance->record($asset);
        }
        $rows = [];
        if ($source === 'resource_costs') {
            $bills = $this->all($this->bills($context, $from, $to)->with('journal')->whereHas('journal', fn ($q) => $q->where('status', 'posted')->whereNull('reversed_by_journal_id')), $watermark, $provenance);
            $trips = $this->all(FleetTrip::whereIn('asset_id', $assets->keys())->whereBetween('started_at', [$from, $to])->where('is_personal', false)->where('consent_blocked', false), $watermark, $provenance)->groupBy('asset_id');
            $fuel = $this->all(FleetFuelLog::whereIn('asset_id', $assets->keys())->whereBetween('logged_at', [$from, $to]), $watermark, $provenance)->groupBy('asset_id');
            foreach ($assets as $asset) {
                $documents = $bills->where('asset_id', $asset->id);
                $cost = $documents->isEmpty() ? null : (float) $documents->sum('total_amount');
                $journeys = $trips->get($asset->id, collect());
                $knownDistances = $journeys->filter(fn ($trip) => $trip->distance_km !== null && $trip->distance_km > 0);
                $distance = $knownDistances->isEmpty() ? null : (float) $knownDistances->sum('distance_km');
                $knownFuel = $fuel->get($asset->id, collect())->whereNotNull('total_cost');
                $rows[] = $this->base('asset-'.$asset->id, $asset, $to, 'Period summary') + [
                    'posted_cost' => $cost, 'distance' => $distance, 'missing_distance' => $journeys->count() - $knownDistances->count(),
                    'cost_per_km' => $cost !== null && $distance > 0 ? $cost / $distance : null, 'fuel_cost' => $knownFuel->isEmpty() ? null : (float) $knownFuel->sum('total_cost'),
                ];
            }

            return $rows;
        }
        if ($source === 'downtime') {
            $model = new class extends Model {};
            $model->setTable('fleet_maintenance_restrictions');
            $query = $model->newQuery()->whereIn('asset_id', $assets->keys())->where('created_at', '<=', $to)
                ->where(fn ($q) => $q->whereNull('released_at')->orWhere('released_at', '>=', $from));
            $items = $this->all($query, $watermark, $provenance);
            foreach ($assets as $asset) {
                $evidence = $items->where('asset_id', $asset->id);
                $intervals = [];
                $unknown = false;
                foreach ($evidence as $item) {
                    if (! $item->released_at && $item->state !== 'active') {
                        $unknown = true;

                        continue;
                    }
                    $start = CarbonImmutable::parse($item->created_at)->max($from);
                    $end = $item->released_at ? CarbonImmutable::parse($item->released_at)->min($to) : $to;
                    if ($end < $start) {
                        $unknown = true;

                        continue;
                    }
                    $intervals[] = [$start->getTimestamp(), $end->getTimestamp()];
                }
                $rows[] = $this->base('asset-'.$asset->id, $asset, $to, 'Recorded restriction duration') + [
                    'restriction_hours' => $unknown ? null : self::unionSeconds($intervals) / 3600, 'restriction_records' => $evidence->count(),
                ];
            }

            return $rows;
        }
        if ($source === 'stocktakes') {
            $query = app(AssetStocktakeService::class)->visibleQuery($context['actor'])->whereIn('site_id', $context['site_ids'])
                ->whereBetween('counted_at', [$from, $to])->whereNotExists(function ($q) use ($assets) {
                    $q->selectRaw('1')->from('asset_stocktake_asset_refs')->whereColumn('asset_stocktake_asset_refs.asset_stocktake_id', 'asset_stocktakes.id')->whereNotIn('asset_id', $assets->keys());
                });
            foreach ($this->all($query, $watermark, $provenance) as $count) {
                $data = app(AssetStocktakeService::class)->present($count);
                $entries = collect($data['entries']);
                $rows[] = ['reference' => 'ST-'.$count->id, 'date' => $count->counted_at->setTimezone('Pacific/Auckland')->toDateString(), 'resource' => $count->title, 'site' => $count->scope['site'] ?? null, 'status' => $count->status,
                    'expected' => $entries->where('expected', true)->count(), 'found' => $entries->where('result', 'found')->count(),
                    'pending' => $entries->where('result', 'pending')->count(),
                    'exceptions' => $entries->filter(fn ($e) => ($e['result'] ?? 'pending') !== 'pending' && (($e['result'] ?? '') !== 'found' || ! ($e['expected'] ?? false) || ($e['changed'] ?? false)))->count()];
            }

            return $rows;
        }
        $query = match ($source) {
            'obligations' => FleetObligationReminder::whereIn('asset_id', $assets->keys())->whereBetween('due_on', [$from->setTimezone('Pacific/Auckland')->toDateString(), $to->setTimezone('Pacific/Auckland')->toDateString()]),
            'custody' => AssetCustodyMovement::whereIn('asset_id', $assets->keys())->whereIn('origin_site_id', $context['site_ids'])->whereIn('destination_site_id', $context['site_ids'])->whereBetween('dispatched_at', [$from, $to]),
            'finance_bills' => $this->bills($context, $from, $to)->with('journal'),
        };
        foreach ($this->all($query, $watermark, $provenance) as $item) {
            $asset = $assets->get($item->asset_id);
            if (! $asset) {
                continue;
            }
            $at = match ($source) {
                'obligations' => $item->due_on,'custody' => $item->dispatched_at,default => $item->bill_date
            };
            $rows[] = $this->base((string) ($item->bill_number ?? $item->id), $asset, $at, $item->state ?? $item->status) + match ($source) {
                'obligations' => ['due_on' => $item->due_on?->toDateString(), 'due_km' => $item->due_km, 'source_type' => $item->source_type, 'source_id' => (string) $item->source_id],
                'custody' => ['received_at' => $item->received_at?->toISOString(), 'returned_at' => $item->returned_at?->toISOString(), 'kind' => $item->kind],
                'finance_bills' => ['cost' => $item->total_amount === null ? null : (float) $item->total_amount, 'paid' => $item->amount_paid === null ? null : (float) $item->amount_paid, 'cost_stage' => 'Supplier invoice', 'journal_status' => $item->journal?->status],
            };
        }

        return $rows;
    }

    private function bills(array $context, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        // Canonical asset and invoice Site must both be within current Finance scope.
        return FinBill::whereIn('asset_id', $context['asset_ids'])->whereIn('site_id', $context['site_ids'])
            ->whereBetween('bill_date', [$from->setTimezone('Pacific/Auckland')->toDateString(), $to->setTimezone('Pacific/Auckland')->toDateString()])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('assets')->whereColumn('assets.id', 'fin_bills.asset_id')->whereColumn('assets.site_id', 'fin_bills.site_id'));
    }

    private function all(Builder $query, array &$watermark, ReportSourceEvidence $provenance): Collection
    {
        $max = (int) (clone $query)->max($query->qualifyColumn('id'));
        $watermark[$query->getModel()->getTable()] = $max;
        $rows = collect();
        foreach ($query->where($query->qualifyColumn('id'), '<=', $max)->lazyById(500) as $row) {
            abort_if($rows->count() >= 100000, 422, 'Too many source rows. Choose a narrower scope.');
            $provenance->record($row);
            $rows->push($row);
        }

        return $rows;
    }

    private function base(string $reference, Asset $asset, $at, ?string $status): array
    {
        return ['reference' => $reference, 'date' => CarbonImmutable::parse($at)->setTimezone('Pacific/Auckland')->toDateString(),
            'resource' => $asset->name, 'site' => $asset->site?->name ?? $asset->homeSite?->name, 'status' => $status];
    }

    /** Half-open intervals, clipped by the caller. Overlaps are counted once. */
    public static function unionSeconds(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $total = 0;
        [$start,$end] = array_shift($intervals);
        foreach ($intervals as [$nextStart,$nextEnd]) {
            if ($nextStart <= $end) {
                $end = max($end, $nextEnd);

                continue;
            }
            $total += max(0, $end - $start);
            [$start,$end] = [$nextStart, $nextEnd];
        }

        return $total + max(0, $end - $start);
    }
}
