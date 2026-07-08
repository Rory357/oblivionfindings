<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * §L Reports — server-computed helpdesk analytics for agents. ONE JSON
 * endpoint (never full tables to the client): point-in-time state (open by
 * priority/category, agent workload, KPI counts) plus flow metrics over a
 * date range (created/resolved trend, SLA compliance, response/resolution
 * times, CSAT, provisioning throughput). Tenant-scoped; Schema-guarded so a
 * pre-migration read returns a zeroed report rather than 500ing.
 */
class ItReportsController extends Controller
{
    use ResolvesHrTenant;

    /** Agent-only (route gated `permission:it.view`); read-only analytics. */
    public function data(Request $request)
    {
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        [$from, $to] = $this->range($request);

        return response()->json($this->report($tenantId, $from, $to));
    }

    /**
     * Per-card CSV export (§L) — the same tenant-scoped, range-bound datasets
     * the Reports tab charts, streamed as a download. Every cell goes through
     * the base Controller's putCsv() so a formula-injection payload in a
     * user-controlled field (a requester or assignee name) can never execute
     * on open. Agent-only (route gated `permission:it.view`).
     */
    public function export(Request $request)
    {
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        [$from, $to] = $this->range($request);
        $card = is_string($request->query('card')) ? $request->query('card') : 'trend';

        [$filename, $headers, $rows] = $this->exportCard($tenantId, $from, $to, $card);

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, $headers);
            foreach ($rows as $row) {
                $this->putCsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Map a card key to [filename, header row, data rows]. An unknown card
     * falls back to the trend export rather than erroring.
     *
     * @return array{0: string, 1: array<int, string>, 2: array<int, array<int, string|int|float>>}
     */
    private function exportCard(int $tenantId, Carbon $from, Carbon $to, string $card): array
    {
        $ready = Schema::hasTable('it_tickets');
        $reqReady = Schema::hasTable('it_provisioning_requests');
        $stamp = "{$from->toDateString()}_{$to->toDateString()}";

        return match ($card) {
            'summary' => [
                "it-report-summary_{$stamp}.csv",
                ['Metric', 'Value'],
                $this->summaryRows(
                    $ready ? $this->kpis($tenantId, $from, $to) : $this->emptyKpis(),
                    $reqReady ? $this->provisioning($tenantId, $from, $to) : $this->emptyProvisioning(),
                ),
            ],
            'by_priority' => [
                "it-open-by-priority_{$stamp}.csv",
                ['Priority', 'Open tickets'],
                $ready ? array_map(fn ($r) => [ucfirst((string) $r['name']), $r['value']], $this->openBy($tenantId, 'priority', ItTicket::PRIORITIES)) : [],
            ],
            'by_category' => [
                "it-open-by-category_{$stamp}.csv",
                ['Category', 'Open tickets'],
                $ready ? array_map(fn ($r) => [ucfirst((string) $r['name']), $r['value']], $this->openBy($tenantId, 'category', ItTicket::CATEGORIES)) : [],
            ],
            'top_requesters' => [
                "it-top-requesters_{$stamp}.csv",
                ['Requester', 'Tickets raised'],
                $ready ? array_map(fn ($r) => [$r['name'], $r['count']], $this->topRequesters($tenantId, $from, $to)) : [],
            ],
            'agent_workload' => [
                "it-agent-workload_{$stamp}.csv",
                ['Assignee', 'Open tickets'],
                $ready ? array_map(fn ($r) => [$r['name'], $r['open']], $this->agentWorkload($tenantId)) : [],
            ],
            default => [
                "it-created-vs-resolved_{$stamp}.csv",
                ['Date', 'Created', 'Resolved'],
                $ready ? array_map(fn ($r) => [$r['date'], $r['created'], $r['resolved']], $this->trend($tenantId, $from, $to)) : [],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $k
     * @param  array<string, mixed>  $p
     * @return array<int, array<int, string|int|float>>
     */
    private function summaryRows(array $k, array $p): array
    {
        return [
            ['Open', $k['open']],
            ['Unassigned', $k['unassigned']],
            ['SLA at risk', $k['breaching']],
            ['SLA breached', $k['breached']],
            ['Resolved in range', $k['resolved']],
            ['Avg first response (mins)', $k['avg_first_response_mins'] ?? ''],
            ['Avg resolution (mins)', $k['avg_resolution_mins'] ?? ''],
            ['SLA compliance (%)', $k['sla_compliance'] ?? ''],
            ['CSAT average', $k['csat_avg'] ?? ''],
            ['CSAT response rate (%)', $k['csat_response_rate'] ?? ''],
            ['Provisioning raised', $p['raised']],
            ['Provisioning fulfilled', $p['fulfilled']],
            ['Avg days to fulfil', $p['avg_days'] ?? ''],
        ];
    }

    /**
     * Clamp the requested window to a sane span; default the last 30 days.
     * A hostile `?from=1900-01-01` can't scan the whole table — capped at 365d.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $to = $this->parseDate($request->query('to')) ?? Carbon::now();
        $from = $this->parseDate($request->query('from')) ?? $to->copy()->subDays(29);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->lt($to->copy()->subDays(365))) {
            $from = $to->copy()->subDays(365);
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function report(int $tenantId, Carbon $from, Carbon $to): array
    {
        $ticketsReady = Schema::hasTable('it_tickets');
        $requestsReady = Schema::hasTable('it_provisioning_requests');

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => (int) abs($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay())) + 1,
            ],
            'kpis' => $ticketsReady ? $this->kpis($tenantId, $from, $to) : $this->emptyKpis(),
            'trend' => $ticketsReady ? $this->trend($tenantId, $from, $to) : [],
            'by_priority' => $ticketsReady ? $this->openBy($tenantId, 'priority', ItTicket::PRIORITIES) : [],
            'by_category' => $ticketsReady ? $this->openBy($tenantId, 'category', ItTicket::CATEGORIES) : [],
            'top_requesters' => $ticketsReady ? $this->topRequesters($tenantId, $from, $to) : [],
            'agent_workload' => $ticketsReady ? $this->agentWorkload($tenantId) : [],
            'provisioning' => $requestsReady ? $this->provisioning($tenantId, $from, $to) : $this->emptyProvisioning(),
        ];
    }

    /**
     * KPI row: point-in-time open state + flow metrics over the range.
     *
     * @return array<string, mixed>
     */
    private function kpis(int $tenantId, Carbon $from, Carbon $to): array
    {
        $state = ItTicket::query()
            ->forTenant($tenantId)
            ->selectRaw(
                "SUM(status IN ('open','in_progress','waiting')) AS open_count,
                 SUM(status IN ('open','in_progress','waiting') AND assigned_to_user_id IS NULL) AS unassigned,
                 SUM(status IN ('open','in_progress','waiting') AND sla_state = 'at_risk') AS breaching,
                 SUM(status IN ('open','in_progress','waiting') AND sla_state = 'breached') AS breached"
            )
            ->first();

        $resolvedInRange = fn () => ItTicket::query()
            ->forTenant($tenantId)
            ->whereBetween('resolved_at', [$from, $to]);

        $resolvedCount = $resolvedInRange()->count();
        $met = $resolvedInRange()->where('sla_state', 'met')->count();
        $measured = $resolvedInRange()->whereIn('sla_state', ['met', 'breached'])->count();
        $avgResolution = $resolvedInRange()->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) AS m')->value('m');
        $csatCount = $resolvedInRange()->whereNotNull('csat_submitted_at')->count();
        $csatAvg = $resolvedInRange()->whereNotNull('csat_submitted_at')->avg('csat_score');

        $avgFirst = ItTicket::query()
            ->forTenant($tenantId)
            ->whereBetween('first_responded_at', [$from, $to])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, first_responded_at)) AS m')
            ->value('m');

        return [
            'open' => (int) ($state->open_count ?? 0),
            'unassigned' => (int) ($state->unassigned ?? 0),
            'breaching' => (int) ($state->breaching ?? 0),
            'breached' => (int) ($state->breached ?? 0),
            'resolved' => $resolvedCount,
            'avg_first_response_mins' => $avgFirst !== null ? (int) round((float) $avgFirst) : null,
            'avg_resolution_mins' => $avgResolution !== null ? (int) round((float) $avgResolution) : null,
            'sla_compliance' => $measured > 0 ? round($met / $measured * 100, 1) : null,
            // Raw met/measured counts back the "X of Y within SLA" microcopy (§S).
            'sla_met' => $met,
            'sla_measured' => $measured,
            'csat_avg' => $csatAvg !== null ? round((float) $csatAvg, 2) : null,
            'csat_response_rate' => $resolvedCount > 0 ? round($csatCount / $resolvedCount * 100, 1) : null,
        ];
    }

    /**
     * Created vs resolved, one point per day across the range (zero-filled so
     * the area chart has no gaps). Buckets by UTC calendar day — good enough
     * for a 30/90-day trend; NZ-day bucketing is a v2 nicety.
     *
     * @return array<int, array{date: string, created: int, resolved: int}>
     */
    private function trend(int $tenantId, Carbon $from, Carbon $to): array
    {
        $created = ItTicket::query()
            ->forTenant($tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $resolved = ItTicket::query()
            ->forTenant($tenantId)
            ->whereBetween('resolved_at', [$from, $to])
            ->selectRaw('DATE(resolved_at) AS d, COUNT(*) AS c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $series = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $series[] = [
                'date' => $key,
                'created' => (int) ($created[$key] ?? 0),
                'resolved' => (int) ($resolved[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Point-in-time open-ticket counts grouped by a trusted enum column,
     * returned in canonical order with zeros so the donut is stable.
     *
     * @param  array<int, string>  $values
     * @return array<int, array{name: string, value: int}>
     */
    private function openBy(int $tenantId, string $column, array $values): array
    {
        $counts = ItTicket::query()
            ->forTenant($tenantId)
            ->whereIn('status', ItTicket::OPEN_STATUSES)
            ->selectRaw("{$column} AS k, COUNT(*) AS c")
            ->groupBy($column)
            ->pluck('c', 'k');

        return array_map(fn (string $v) => ['name' => $v, 'value' => (int) ($counts[$v] ?? 0)], $values);
    }

    /**
     * Top requesters by volume raised in the range.
     *
     * @return array<int, array{name: string, count: int}>
     */
    private function topRequesters(int $tenantId, Carbon $from, Carbon $to): array
    {
        return ItTicket::query()
            ->forTenant($tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('requester_user_id')
            ->selectRaw('requester_user_id, COUNT(*) AS c')
            ->groupBy('requester_user_id')
            ->orderByDesc('c')
            ->limit(8)
            ->with('requester:id,name')
            ->get()
            ->map(fn (ItTicket $t) => ['name' => $t->requester?->name ?? 'Unknown', 'count' => (int) $t->c])
            ->all();
    }

    /**
     * Open tickets per assignee right now (workload balance).
     *
     * @return array<int, array{name: string, open: int}>
     */
    private function agentWorkload(int $tenantId): array
    {
        return ItTicket::query()
            ->forTenant($tenantId)
            ->whereIn('status', ItTicket::OPEN_STATUSES)
            ->whereNotNull('assigned_to_user_id')
            ->selectRaw('assigned_to_user_id, COUNT(*) AS c')
            ->groupBy('assigned_to_user_id')
            ->orderByDesc('c')
            ->limit(12)
            ->with('assignee:id,name')
            ->get()
            ->map(fn (ItTicket $t) => ['name' => $t->assignee?->name ?? 'Unknown', 'open' => (int) $t->c])
            ->all();
    }

    /**
     * Provisioning throughput over the range: raised vs fulfilled and the
     * average days-to-fulfil (via the bridge's `fulfilled_at` stamp).
     *
     * @return array{raised: int, fulfilled: int, avg_days: float|null}
     */
    private function provisioning(int $tenantId, Carbon $from, Carbon $to): array
    {
        $raised = ItProvisioningRequest::query()
            ->forTenant($tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $fulfilled = fn () => ItProvisioningRequest::query()
            ->forTenant($tenantId)
            ->where('status', 'done')
            ->whereBetween('fulfilled_at', [$from, $to]);

        $avgHours = $fulfilled()
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, fulfilled_at)) AS h')
            ->value('h');

        return [
            'raised' => $raised,
            'fulfilled' => $fulfilled()->count(),
            'avg_days' => $avgHours !== null ? round((float) $avgHours / 24, 1) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyKpis(): array
    {
        return [
            'open' => 0, 'unassigned' => 0, 'breaching' => 0, 'breached' => 0, 'resolved' => 0,
            'avg_first_response_mins' => null, 'avg_resolution_mins' => null,
            'sla_compliance' => null, 'sla_met' => 0, 'sla_measured' => 0,
            'csat_avg' => null, 'csat_response_rate' => null,
        ];
    }

    /** @return array{raised: int, fulfilled: int, avg_days: null} */
    private function emptyProvisioning(): array
    {
        return ['raised' => 0, 'fulfilled' => 0, 'avg_days' => null];
    }
}
