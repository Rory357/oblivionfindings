<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only source projection of estimates and internal provider windows. */
class MaintenanceWindowObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'asset';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        $rows = DB::table('fleet_maintenance_reports as report')
            ->join('assets as asset', 'asset.id', '=', 'report.asset_id')
            ->join('sites as site', 'site.id', '=', 'asset.site_id')
            ->join('fleet_work_orders as work', 'work.id', '=', 'report.work_order_id')
            ->whereIn('asset.site_id', $siteIds)
            ->whereNotNull('report.estimated_start_date')
            ->whereNotNull('report.estimated_end_date')
            ->where('report.estimated_start_date', '<=', $end->toDateString())
            ->where('report.estimated_end_date', '>=', $start->toDateString())
            ->orderBy('report.id')
            ->get(['report.id', 'report.work_order_id', 'report.estimated_start_date',
                'report.estimated_end_date', 'asset.name as asset_name', 'asset.asset_tag',
                'site.id as site_id', 'site.name as site_name', 'site.type as site_type',
                'work.reference_number']);

        $estimates = $rows->map(static function ($row): CalendarItem {
            $zone = 'Pacific/Auckland';
            $startAt = Carbon::parse($row->estimated_start_date, $zone)->startOfDay();
            $endAt = Carbon::parse($row->estimated_end_date, $zone)->addDay()->startOfDay();

            return new CalendarItem(
                id: 'maintenance-estimate-'.$row->id,
                source: 'asset', group: 'auto',
                title: 'Estimated maintenance — '.($row->asset_name ?: $row->asset_tag ?: 'Asset'),
                start: $startAt->toIso8601String(), end: $endAt->toIso8601String(),
                allDay: true, status: 'scheduled', ref: $row->reference_number,
                site: ['id' => (int) $row->site_id, 'name' => $row->site_name, 'type' => $row->site_type],
                link: '/fleet-assets/maintenance/work-orders/'.$row->work_order_id,
                desc: 'Planning estimate from a maintenance report. Provider confirmation is separate.',
            );
        })->all();

        return [...$estimates, ...$this->providerWindows($siteIds, $start, $end)];
    }

    /** Latest source action wins; retries and changed plans keep one stable calendar identity. */
    private function providerWindows(array $siteIds, Carbon $start, Carbon $end): array
    {
        $candidateIds = DB::table('fleet_maintenance_actions as action')
            ->join('fleet_work_orders as work', 'work.id', '=', 'action.work_order_id')
            ->join('assets as asset', 'asset.id', '=', 'work.asset_id')
            ->whereIn('asset.site_id', $siteIds)
            ->where('action.action_type', 'plan_provider')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(action.payload_json, '$.starts_at')) < ?", [$end->copy()->utc()->toDateTimeString()])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(action.payload_json, '$.ends_at')) > ?", [$start->copy()->utc()->toDateTimeString()])
            ->distinct()->pluck('action.work_order_id')->all();
        if ($candidateIds === []) {
            return [];
        }

        $actions = DB::table('fleet_maintenance_actions')
            ->whereIn('work_order_id', $candidateIds)
            ->whereIn('action_type', ['plan_provider', 'record_provider_confirmation',
                'record_provider_cancellation', 'record_provider_completion'])
            ->orderBy('id')->get(['work_order_id', 'action_type', 'payload_json']);
        $latest = [];
        foreach ($actions as $action) {
            $id = (int) $action->work_order_id;
            $latest[$id]['state'] = $action->action_type;
            if ($action->action_type === 'plan_provider') {
                $latest[$id]['plan'] = json_decode((string) $action->payload_json, true);
            }
        }
        $work = DB::table('fleet_work_orders as work')
            ->join('assets as asset', 'asset.id', '=', 'work.asset_id')
            ->join('sites as site', 'site.id', '=', 'asset.site_id')
            ->whereIn('work.id', $candidateIds)
            ->get(['work.id', 'work.reference_number', 'asset.name as asset_name',
                'site.id as site_id', 'site.name as site_name', 'site.type as site_type']);
        $items = [];
        foreach ($work as $row) {
            $source = $latest[(int) $row->id] ?? null;
            $plan = $source['plan'] ?? null;
            if (! is_array($plan) || empty($plan['starts_at']) || empty($plan['ends_at'])) {
                continue;
            }
            $starts = Carbon::parse($plan['starts_at'], 'UTC');
            $ends = Carbon::parse($plan['ends_at'], 'UTC');
            if (! $starts->lessThan($end) || ! $ends->greaterThan($start)) {
                continue;
            }
            $state = match ($source['state']) {
                'record_provider_confirmation' => 'confirmed',
                'record_provider_cancellation' => 'cancelled',
                'record_provider_completion' => 'completed',
                default => 'planned',
            };
            $items[] = new CalendarItem(
                id: 'maintenance-provider-'.$row->id,
                source: 'asset', group: 'auto',
                title: 'Provider appointment ('.$state.') — '.$row->asset_name,
                start: $starts->setTimezone('Pacific/Auckland')->toIso8601String(),
                end: $ends->setTimezone('Pacific/Auckland')->toIso8601String(),
                status: $state, ref: $row->reference_number,
                site: ['id' => (int) $row->site_id, 'name' => $row->site_name, 'type' => $row->site_type],
                link: '/fleet-assets/maintenance/work-orders/'.$row->id,
                desc: 'Internal provider plan: '.($plan['provider_name'] ?? 'Provider').'. Provider response is recorded separately.',
            );
        }

        return $items;
    }
}
