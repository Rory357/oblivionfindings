<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItProvisioningAccessService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\ItAutomationRun;
use App\Models\ItChange;
use App\Models\ItMajorIncident;
use App\Models\ItProblem;
use App\Models\ItProvisioningRequest;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * §L Reports — server-computed helpdesk analytics for agents. ONE JSON
 * endpoint (never full tables to the client): point-in-time state (open by
 * priority/category, agent workload, KPI counts) plus flow metrics over a
 * date range (created/resolved trend, SLA compliance, response/resolution
 * times, CSAT, provisioning throughput). Access-scoped; Schema-guarded so a
 * pre-migration read returns a zeroed report rather than 500ing.
 */
class ItReportsController extends Controller
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItProvisioningAccessService $provisioningAccess,
        private readonly SecurityDevicesAccessService $deviceAccess,
    ) {}

    /** Agent-only (route gated `permission:it.view`); read-only analytics. */
    public function data(Request $request)
    {
        $user = $request->user();
        [$from, $to] = $this->range($request);

        return response()->json($this->report($from, $to, $user));
    }

    /**
     * Per-card CSV export (§L) — the same access-scoped, range-bound datasets
     * the Reports tab charts, streamed as a download. Every cell goes through
     * the base Controller's putCsv() so a formula-injection payload in a
     * user-controlled field (a requester or assignee name) can never execute
     * on open. Agent-only (route gated `permission:it.view`).
     */
    public function export(Request $request)
    {
        $user = $request->user();
        [$from, $to] = $this->range($request);
        $card = is_string($request->query('card')) ? $request->query('card') : 'trend';

        [$filename, $headers, $rows] = $this->exportCard($from, $to, $card, $user);

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
    private function exportCard(Carbon $from, Carbon $to, string $card, User $user): array
    {
        $ready = Schema::hasTable('it_tickets');
        $reqReady = Schema::hasTable('it_provisioning_requests');
        $stamp = "{$from->toDateString()}_{$to->toDateString()}";

        return match ($card) {
            'summary' => [
                "it-report-summary_{$stamp}.csv",
                ['Metric', 'Value'],
                $this->summaryRows(
                    $ready ? $this->kpis($from, $to, $user) : $this->emptyKpis(),
                    $reqReady ? $this->provisioning($from, $to, $user) : $this->emptyProvisioning(),
                ),
            ],
            'by_priority' => [
                "it-open-by-priority_{$stamp}.csv",
                ['Priority', 'Open tickets'],
                $ready ? array_map(fn ($r) => [ucfirst((string) $r['name']), $r['value']], $this->openBy('priority', ItTicket::PRIORITIES, $user)) : [],
            ],
            'by_category' => [
                "it-open-by-category_{$stamp}.csv",
                ['Category', 'Open tickets'],
                $ready ? array_map(fn ($r) => [ucfirst((string) $r['name']), $r['value']], $this->openBy('category', ItTicket::CATEGORIES, $user)) : [],
            ],
            'top_requesters' => [
                "it-top-requesters_{$stamp}.csv",
                ['Requester', 'Tickets raised'],
                $ready ? array_map(fn ($r) => [$r['name'], $r['count']], $this->topRequesters($from, $to, $user)) : [],
            ],
            'agent_workload' => [
                "it-agent-workload_{$stamp}.csv",
                ['Assignee', 'Open tickets'],
                $ready ? array_map(fn ($r) => [$r['name'], $r['open']], $this->agentWorkload($user)) : [],
            ],
            default => [
                "it-created-vs-resolved_{$stamp}.csv",
                ['Date', 'Created', 'Resolved'],
                $ready ? array_map(fn ($r) => [$r['date'], $r['created'], $r['resolved']], $this->trend($from, $to, $user)) : [],
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
    private function report(Carbon $from, Carbon $to, User $user): array
    {
        $ticketsReady = Schema::hasTable('it_tickets');
        $requestsReady = Schema::hasTable('it_provisioning_requests');

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => (int) abs($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay())) + 1,
            ],
            'kpis' => $ticketsReady ? $this->kpis($from, $to, $user) : $this->emptyKpis(),
            'trend' => $ticketsReady ? $this->trend($from, $to, $user) : [],
            'by_priority' => $ticketsReady ? $this->openBy('priority', ItTicket::PRIORITIES, $user) : [],
            'by_category' => $ticketsReady ? $this->openBy('category', ItTicket::CATEGORIES, $user) : [],
            'top_requesters' => $ticketsReady ? $this->topRequesters($from, $to, $user) : [],
            'agent_workload' => $ticketsReady ? $this->agentWorkload($user) : [],
            'provisioning' => $requestsReady ? $this->provisioning($from, $to, $user) : $this->emptyProvisioning(),
            'backlog_age' => $ticketsReady ? $this->backlogAge($user) : $this->emptyBacklogAge(),
            'reopen_rate' => $ticketsReady ? $this->reopenRate($from, $to, $user) : $this->emptyRate(),
            'first_contact_resolution' => $ticketsReady ? $this->firstContactResolution($from, $to, $user) : $this->emptyRate(),
            'channels' => $ticketsReady ? $this->channels($from, $to, $user) : [],
            'major_incidents' => Schema::hasTable('it_major_incidents') ? $this->majorIncidents($from, $to, $user) : ['declared' => 0, 'restored' => 0, 'open' => 0],
            'change_success' => Schema::hasTable('it_changes') ? $this->changeSuccess($from, $to, $user) : ['successful' => 0, 'failed' => 0, 'inconclusive' => 0],
            'recurring_problems' => Schema::hasTable('it_problems') ? $this->problemOutcomes($from, $to, $user) : ['total' => 0, 'known_errors' => 0, 'root_causes' => 0],
            'automation_outcomes' => $this->automationOutcomes($from, $to, $user),
            'service_reliability' => Schema::hasTable('it_services') ? $this->serviceReliability($from, $to, $user) : [],
            'device_reliability' => $this->deviceReliability($from, $to, $user),
            'quality' => $ticketsReady ? $this->qualityGaps($user) : [],
        ];
    }

    /** @return array<string, array{count: int, href: string}> */
    private function backlogAge(User $user): array
    {
        $base = fn () => $this->ticketQuery($user)->whereIn('status', ItTicket::OPEN_STATUSES);
        $now = now();
        $twoDays = $now->copy()->subDays(2);
        $sevenDays = $now->copy()->subDays(7);
        $thirtyDays = $now->copy()->subDays(30);

        return [
            'under_2_days' => ['count' => $base()->where('created_at', '>=', $twoDays)->count(), 'href' => $this->ticketHref(['age' => 'under_2', 'open_only' => 1])],
            'days_2_to_7' => ['count' => $base()->where('created_at', '>=', $sevenDays)->where('created_at', '<', $twoDays)->count(), 'href' => $this->ticketHref(['age' => '2_7', 'open_only' => 1])],
            'days_8_to_30' => ['count' => $base()->where('created_at', '>=', $thirtyDays)->where('created_at', '<', $sevenDays)->count(), 'href' => $this->ticketHref(['age' => '8_30', 'open_only' => 1])],
            'over_30_days' => ['count' => $base()->where('created_at', '<', $thirtyDays)->count(), 'href' => $this->ticketHref(['age' => 'over_30', 'open_only' => 1])],
        ];
    }

    /** @return array<string, int|float|null|string> */
    private function reopenRate(Carbon $from, Carbon $to, User $user): array
    {
        $resolved = $this->ticketQuery($user)->whereBetween('resolved_at', [$from, $to]);
        $total = (clone $resolved)->count();
        $reopened = (clone $resolved)->where('reopened_count', '>', 0)->count();

        return [
            'resolved' => $total,
            'reopened' => $reopened,
            'rate' => $total > 0 ? round($reopened / $total * 100, 1) : null,
            'href' => $this->ticketHref([
                'reopened' => 1,
                'resolved_from' => $from->toDateString(),
                'resolved_to' => $to->toDateString(),
            ]),
        ];
    }

    /** @return array<string, int|float|null|string> */
    private function firstContactResolution(Carbon $from, Carbon $to, User $user): array
    {
        $resolved = $this->ticketQuery($user)->whereBetween('resolved_at', [$from, $to]);
        $total = (clone $resolved)->count();
        $firstContact = (clone $resolved)->firstContactResolved()->count();

        return [
            'resolved' => $total,
            'first_contact' => min($firstContact, $total),
            'rate' => $total > 0 ? round(min($firstContact, $total) / $total * 100, 1) : null,
            'href' => $this->ticketHref([
                'first_contact' => 1,
                'resolved_from' => $from->toDateString(),
                'resolved_to' => $to->toDateString(),
            ]),
        ];
    }

    /** @return array<string, array{count: int, href: string}> */
    private function channels(Carbon $from, Carbon $to, User $user): array
    {
        $counts = $this->ticketQuery($user)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('source, COUNT(*) AS aggregate')
            ->groupBy('source')
            ->pluck('aggregate', 'source');

        return collect(ItTicket::SOURCES)
            ->mapWithKeys(fn (string $source) => [$source => [
                'count' => (int) ($counts[$source] ?? 0),
                'href' => $this->ticketHref([
                    'source' => $source,
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ]),
            ]])
            ->all();
    }

    /** @return array<string, int|float|null|string> */
    private function majorIncidents(Carbon $from, Carbon $to, User $user): array
    {
        $query = ItMajorIncident::query()
            ->whereHas('ticket', fn ($ticket) => $this->workAccess->applyViewScope($ticket, $user))
            ->whereBetween('declared_at', [$from, $to]);
        $declared = (clone $query)->count();
        $restored = (clone $query)->whereNotNull('restored_at')->count();
        $avg = (clone $query)->whereNotNull('restored_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, declared_at, restored_at)) AS minutes')
            ->value('minutes');

        return [
            'declared' => $declared,
            'restored' => $restored,
            'open' => $declared - $restored,
            'avg_restore_minutes' => $avg === null ? null : (int) round((float) $avg),
            'href' => $this->periodHref('/it/major-incidents', $from, $to),
        ];
    }

    /** @return array<string, int|float|null|string> */
    private function changeSuccess(Carbon $from, Carbon $to, User $user): array
    {
        $query = ItChange::query()
            ->whereHas('ticket', fn ($ticket) => $this->workAccess->applyViewScope($ticket, $user))
            ->whereBetween('validated_at', [$from, $to]);
        $counts = (clone $query)->selectRaw('validation_result, COUNT(*) AS aggregate')->groupBy('validation_result')->pluck('aggregate', 'validation_result');
        $measured = (int) $counts->sum();

        return [
            'successful' => (int) ($counts['successful'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'inconclusive' => (int) ($counts['inconclusive'] ?? 0),
            'success_rate' => $measured > 0 ? round(((int) ($counts['successful'] ?? 0)) / $measured * 100, 1) : null,
            'href' => $this->periodHref('/it/changes', $from, $to),
        ];
    }

    /** @return array<string, int|string> */
    private function problemOutcomes(Carbon $from, Carbon $to, User $user): array
    {
        $query = ItProblem::query()
            ->whereHas('ticket', fn ($ticket) => $this->workAccess->applyViewScope($ticket, $user))
            ->whereBetween('created_at', [$from, $to]);

        return [
            'total' => (clone $query)->count(),
            'known_errors' => (clone $query)->whereNotNull('known_error_at')->count(),
            'root_causes' => (clone $query)->whereNotNull('root_cause')->where('root_cause', '!=', '')->count(),
            'href' => $this->periodHref('/it/problems', $from, $to),
        ];
    }

    /** @return array<string, int|string|null> */
    private function automationOutcomes(Carbon $from, Carbon $to, User $user): array
    {
        // Automation definitions and their run history are owned by the
        // management-only Setup workspace. A read-only queue viewer must not
        // receive application-wide run counts or a destination they cannot
        // open.
        if (! $user->canDo('it.manage')) {
            return [
                'access' => 'restricted',
                'succeeded' => null,
                'failed' => null,
                'skipped' => null,
                'href' => null,
            ];
        }

        if (! Schema::hasTable('it_automation_runs')) {
            return [
                'access' => 'allowed',
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
                'href' => null,
            ];
        }

        $counts = ItAutomationRun::query()
            ->whereBetween('started_at', [$from, $to])
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'access' => 'allowed',
            'succeeded' => (int) ($counts['succeeded'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'skipped' => (int) ($counts['skipped'] ?? 0),
            'href' => '/it/setup?'.http_build_query([
                'tab' => 'operations',
                'automation_from' => $from->toDateString(),
                'automation_to' => $to->toDateString(),
            ]).'#automations',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function serviceReliability(Carbon $from, Carbon $to, User $user): array
    {
        return ItService::query()
            ->withCount([
                'tickets as ticket_count' => fn ($query) => $this->workAccess->applyViewScope($query, $user)->whereBetween('created_at', [$from, $to]),
                'tickets as open_count' => fn ($query) => $this->workAccess->applyViewScope($query, $user)->whereIn('status', ItTicket::OPEN_STATUSES),
                'tickets as sla_breach_count' => fn ($query) => $this->workAccess->applyViewScope($query, $user)->whereBetween('created_at', [$from, $to])->where('sla_state', 'breached'),
            ])
            ->orderByDesc('ticket_count')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (ItService $service) => [
                'service_id' => $service->id,
                'service' => $service->name,
                'status' => $service->status,
                'tickets' => (int) $service->ticket_count,
                'open' => (int) $service->open_count,
                'sla_breaches' => (int) $service->sla_breach_count,
                'href' => $this->ticketHref([
                    'service' => $service->id,
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ]),
            ])
            ->all();
    }

    /** @return array<string, int|string|null> */
    private function deviceReliability(Carbon $from, Carbon $to, User $user): array
    {
        // IT owns the ticket projection, but Security & Devices remains the
        // source of truth for Device identity and visibility. Do not turn an
        // IT destination permission into a Device-existence side channel.
        if (! $user->canDo('securityDevices.devices.view')) {
            return [
                'access' => 'restricted',
                'affected_devices' => null,
                'open_incidents' => null,
                'recovered' => null,
                'href' => null,
            ];
        }

        if (! Schema::hasTable('it_ticket_links')) {
            return [
                'access' => 'allowed',
                'affected_devices' => 0,
                'open_incidents' => 0,
                'recovered' => 0,
                'href' => null,
            ];
        }

        $query = ItTicketLink::query()
            ->where('linkable_type', 'security_device')
            ->where('relationship', 'affected_device')
            ->whereIn('linkable_id', $this->deviceAccess->visibleDevices($user)->select('devices.id'))
            ->whereHas('ticket', fn ($ticket) => $this->workAccess
                ->applyViewScope($ticket, $user)
                ->where('work_type', 'incident')
                ->whereBetween('created_at', [$from, $to]));

        return [
            'access' => 'allowed',
            'affected_devices' => (clone $query)->distinct('linkable_id')->count('linkable_id'),
            'open_incidents' => (clone $query)
                ->whereHas('ticket', fn ($ticket) => $this->workAccess
                    ->applyViewScope($ticket, $user)
                    ->where('work_type', 'incident')
                    ->whereIn('status', ItTicket::OPEN_STATUSES))
                ->distinct('ticket_id')
                ->count('ticket_id'),
            'recovered' => (clone $query)
                ->whereHas('ticket', fn ($ticket) => $this->workAccess
                    ->applyViewScope($ticket, $user)
                    ->where('work_type', 'incident')
                    ->whereNotNull('monitoring_recovered_at'))
                ->distinct('ticket_id')
                ->count('ticket_id'),
            'href' => $this->ticketHref([
                'device_linked' => 1,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]),
        ];
    }

    /** @return array<string, array{count: int, href: string}> */
    private function qualityGaps(User $user): array
    {
        $base = fn () => $this->ticketQuery($user)->whereIn('status', ItTicket::OPEN_STATUSES);

        return [
            'missing_service' => ['count' => $base()->whereNull('it_service_id')->count(), 'href' => $this->ticketHref(['missing' => 'service', 'open_only' => 1])],
            'missing_queue' => ['count' => $base()->whereNull('queue_id')->count(), 'href' => $this->ticketHref(['missing' => 'queue', 'open_only' => 1])],
            'missing_team' => ['count' => $base()->whereNull('team_id')->count(), 'href' => $this->ticketHref(['missing' => 'team', 'open_only' => 1])],
            'unassigned' => ['count' => $base()->whereNull('assigned_to_user_id')->count(), 'href' => $this->ticketHref(['missing' => 'assignee', 'open_only' => 1])],
        ];
    }

    /** @param array<string, int|string> $filters */
    private function ticketHref(array $filters): string
    {
        return '/it?'.http_build_query(['tab' => 'tickets', ...$filters]);
    }

    private function periodHref(string $path, Carbon $from, Carbon $to): string
    {
        return $path.'?'.http_build_query([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    /**
     * KPI row: point-in-time open state + flow metrics over the range.
     *
     * @return array<string, mixed>
     */
    private function kpis(Carbon $from, Carbon $to, User $user): array
    {
        $state = $this->ticketQuery($user)
            ->selectRaw(
                "SUM(status IN ('open','in_progress','waiting')) AS open_count,
                 SUM(status IN ('open','in_progress','waiting') AND assigned_to_user_id IS NULL) AS unassigned,
                 SUM(status IN ('open','in_progress','waiting') AND sla_state = 'at_risk') AS breaching,
                 SUM(status IN ('open','in_progress','waiting') AND sla_state = 'breached') AS breached"
            )
            ->first();

        $resolvedInRange = fn () => $this->ticketQuery($user)
            ->whereBetween('resolved_at', [$from, $to]);

        $resolvedCount = $resolvedInRange()->count();
        $met = $resolvedInRange()->where('sla_state', 'met')->count();
        $measured = $resolvedInRange()->whereIn('sla_state', ['met', 'breached'])->count();
        $avgResolution = $resolvedInRange()->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) AS m')->value('m');
        $csatCount = $resolvedInRange()->whereNotNull('csat_submitted_at')->count();
        $csatAvg = $resolvedInRange()->whereNotNull('csat_submitted_at')->avg('csat_score');

        $avgFirst = $this->ticketQuery($user)
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
    private function trend(Carbon $from, Carbon $to, User $user): array
    {
        $created = $this->ticketQuery($user)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $resolved = $this->ticketQuery($user)
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
    private function openBy(string $column, array $values, User $user): array
    {
        $counts = $this->ticketQuery($user)
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
    private function topRequesters(Carbon $from, Carbon $to, User $user): array
    {
        return $this->ticketQuery($user)
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
    private function agentWorkload(User $user): array
    {
        return $this->ticketQuery($user)
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
    private function provisioning(Carbon $from, Carbon $to, User $user): array
    {
        $raised = $this->provisioningAccess
            ->applyRequestScope(ItProvisioningRequest::query(), $user)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $fulfilled = fn () => $this->provisioningAccess
            ->applyRequestScope(ItProvisioningRequest::query(), $user)
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

    /** @return array<string, array{count: int, href: string}> */
    private function emptyBacklogAge(): array
    {
        return [
            'under_2_days' => ['count' => 0, 'href' => '/it?tab=tickets&age=under_2'],
            'days_2_to_7' => ['count' => 0, 'href' => '/it?tab=tickets&age=2_7'],
            'days_8_to_30' => ['count' => 0, 'href' => '/it?tab=tickets&age=8_30'],
            'over_30_days' => ['count' => 0, 'href' => '/it?tab=tickets&age=over_30'],
        ];
    }

    /** @return array{resolved: int, reopened: int, rate: null, href: string} */
    private function emptyRate(): array
    {
        return ['resolved' => 0, 'reopened' => 0, 'rate' => null, 'href' => '/it?tab=tickets'];
    }

    /** @return Builder<ItTicket> */
    private function ticketQuery(User $user): Builder
    {
        return $this->workAccess->applyViewScope(ItTicket::query(), $user);
    }
}
