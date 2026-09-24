<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\FleetOuting;
use App\Models\FleetOutingResident;
use App\Models\FleetResidentTransport;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetTripSiteScope;
use App\Services\Fleet\ResidentTransportJourneyScope;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class CommunityAccessController extends Controller
{
    /**
     * FA-T01: every outing, resident row, weekly count and CSV covers the
     * viewer's approved Sites only. `fleet.manage` is the explicit all-Sites
     * authority, as in the sibling Fleet report controllers.
     */
    private const SITE_BYPASS_PERMISSIONS = ['fleet.manage'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly ResidentTransportJourneyScope $journeyScope,
    ) {}

    public function index(Request $request)
    {
        $days = (int) ($request->input('days', 30));
        $days = in_array($days, [30, 90, 180, 365]) ? $days : 30;
        $since = now()->subDays($days);

        $hasOutings = Schema::hasTable('fleet_outings');
        $hasOutingResidents = Schema::hasTable('fleet_outing_residents');
        $hasTransports = Schema::hasTable('fleet_resident_transports');

        $byResident = [];
        $weeklyTrend = [];
        $totalOutings = 0;
        $totalHours = 0;

        // Gather outing data per resident — visible outings, visible residents only.
        if ($hasOutings && $hasOutingResidents) {
            $user = $request->user();
            $outingResidents = FleetOutingResident::query()
                ->whereHas('outing', fn (Builder $outing) => $this->applyOutingScope($outing, $user)
                    ->where('created_at', '>=', $since))
                ->whereHas('client', fn (Builder $client) => $this->journeyScope->applyClientScope($client, $user))
                ->with([
                    'outing:id,planned_departure,planned_return,actual_departure,actual_return,status',
                    'client:id,first_name,last_name,site_id',
                ])
                ->get();

            foreach ($outingResidents as $or) {
                $clientId = $or->client_id;
                if (!$clientId) {
                    continue;
                }

                $clientName = $or->client
                    ? trim(($or->client->first_name ?? '') . ' ' . ($or->client->last_name ?? ''))
                    : "Resident #{$clientId}";
                if (empty(trim($clientName))) {
                    $clientName = "Resident #{$clientId}";
                }

                $siteId = $or->client?->site_id;

                if (!isset($byResident[$clientId])) {
                    $byResident[$clientId] = [
                        'id' => $clientId,
                        'name' => $clientName,
                        'house' => '',
                        'site_id' => $siteId,
                        'outings' => 0,
                        'transport_trips' => 0,
                        'total_hours' => 0,
                        'last_outing' => null,
                    ];
                }

                $byResident[$clientId]['outings']++;
                $totalOutings++;

                // Calculate duration in hours
                $outing = $or->outing;
                if ($outing) {
                    $start = $outing->actual_departure ?? $outing->planned_departure;
                    $end = $outing->actual_return ?? $outing->planned_return;
                    if ($start && $end) {
                        $hours = $start->diffInMinutes($end) / 60;
                        $byResident[$clientId]['total_hours'] += round($hours, 1);
                        $totalHours += $hours;
                    }

                    // Track last outing
                    $outingDate = ($outing->actual_departure ?? $outing->planned_departure)?->toISOString();
                    if ($outingDate && (!$byResident[$clientId]['last_outing'] || $outingDate > $byResident[$clientId]['last_outing'])) {
                        $byResident[$clientId]['last_outing'] = $outingDate;
                    }
                }
            }

            // Weekly trend (single grouped query instead of N queries)
            $weekStart = $since->copy();
            $weekBuckets = [];
            while ($weekStart->lt(now())) {
                $weekBuckets[] = [
                    'start' => $weekStart->copy(),
                    'label' => $weekStart->format('d M'),
                ];
                $weekStart = $weekStart->copy()->addDays(7);
            }

            $weeklyCounts = $this->applyOutingScope(FleetOuting::query(), $user)
                ->where('created_at', '>=', $since)
                ->selectRaw('FLOOR(DATEDIFF(created_at, ?) / 7) as week_index, COUNT(*) as cnt', [$since->toDateString()])
                ->groupBy('week_index')
                ->pluck('cnt', 'week_index');

            foreach ($weekBuckets as $i => $bucket) {
                $weeklyTrend[] = [
                    'label' => $bucket['label'],
                    'value' => (int) ($weeklyCounts[$i] ?? 0),
                ];
            }
        }

        // Transport trip counts per resident — the same resident-journey
        // boundary as the Transport register.
        if ($hasTransports) {
            $transportCounts = $this->journeyScope
                ->applyTransportScope(FleetResidentTransport::query(), $request->user())
                ->where('created_at', '>=', $since)
                ->selectRaw('resident_id, COUNT(*) as cnt')
                ->groupBy('resident_id')
                ->pluck('cnt', 'resident_id')
                ->toArray();

            foreach ($transportCounts as $residentId => $count) {
                if (isset($byResident[$residentId])) {
                    $byResident[$residentId]['transport_trips'] = $count;
                } else {
                    // Resident has transports but no outings
                    $byResident[$residentId] = [
                        'id' => $residentId,
                        'name' => "Resident #{$residentId}",
                        'house' => '',
                        'site_id' => null,
                        'outings' => 0,
                        'transport_trips' => $count,
                        'total_hours' => 0,
                        'last_outing' => null,
                    ];
                }
            }
        }

        // Resolve house names — approved Sites only
        $siteIds = collect($byResident)->pluck('site_id')->filter()->unique()->values()->all();
        if (!empty($siteIds)) {
            $siteNames = $this->siteAccess
                ->applySiteScope(Site::query(), $request->user(), self::SITE_BYPASS_PERMISSIONS)
                ->whereIn('id', $siteIds)
                ->pluck('name', 'id')
                ->toArray();
            foreach ($byResident as &$r) {
                if ($r['site_id'] && isset($siteNames[$r['site_id']])) {
                    $r['house'] = $siteNames[$r['site_id']];
                }
            }
            unset($r);
        }

        // Remove site_id from output
        $byResident = array_map(function ($r) {
            unset($r['site_id']);
            $r['total_hours'] = round($r['total_hours'], 1);
            return $r;
        }, array_values($byResident));

        // Sort by outings descending
        usort($byResident, fn ($a, $b) => $b['outings'] <=> $a['outings']);

        // KPIs
        $residentsParticipating = count(array_filter($byResident, fn ($r) => $r['outings'] > 0));
        $totalResidents = count($byResident);
        $avgHoursPerResident = $residentsParticipating > 0 ? round($totalHours / $residentsParticipating, 1) : 0;

        // Community Access Target %: % of residents with 2+ outings in period
        $residentsWithTarget = count(array_filter($byResident, fn ($r) => $r['outings'] >= 2));
        $accessTargetPct = $totalResidents > 0 ? round(($residentsWithTarget / $totalResidents) * 100, 1) : 0;

        // CSV Export
        if ($request->input('export') === 'csv') {
            return response()->streamDownload(function () use ($byResident) {
                $handle = fopen('php://output', 'w');
                $this->putCsv($handle, ['Resident', 'House', 'Outings', 'Transport Trips', 'Total Hours', 'Last Outing']);
                foreach ($byResident as $r) {
                    $this->putCsv($handle, [
                        $r['name'],
                        $r['house'],
                        $r['outings'],
                        $r['transport_trips'],
                        $r['total_hours'],
                        $r['last_outing'] ? date('Y-m-d', strtotime($r['last_outing'])) : '',
                    ]);
                }
                fclose($handle);
            }, 'community-access-' . now()->format('Y-m-d') . '.csv');
        }

        return Inertia::render('fleet-assets/reports/community-access', [
            'by_resident' => $byResident,
            'weekly_trend' => $weeklyTrend,
            'days' => $days,
            'stats' => [
                'total_outings' => $totalOutings,
                'residents_participating' => $residentsParticipating,
                'avg_hours_per_resident' => $avgHoursPerResident,
                'total_hours' => round($totalHours, 1),
                'access_target_pct' => $accessTargetPct,
            ],
        ]);
    }

    /**
     * The outing rule ResidentTrackingController::applyOutingScope applies:
     * an outing is visible when its vehicle is, and it carries at least one
     * resident the viewer may see. Here both sides use the Fleet report
     * boundary (approved Sites, `fleet.manage` bypass).
     */
    private function applyOutingScope(Builder $query, ?User $user): Builder
    {
        $vehicles = FleetTripSiteScope::vehicles(
            $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS),
        );

        return $query
            ->whereIn($query->qualifyColumn('asset_id'), $vehicles->select('assets.id'))
            ->whereHas('clients', fn (Builder $clients) => $this->journeyScope->applyClientScope($clients, $user));
    }
}
