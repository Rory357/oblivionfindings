<?php

namespace App\Services\Overview;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\StrategicGoal;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Domain\Hr\Models\HrCourseAssignment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\ComplianceRequirementApplicabilityService;
use App\Domain\Hr\Services\HrLeaveAccessService;
use App\Domain\Hr\Services\HrLifecycleAccessService;
use App\Domain\Hr\Services\HrRecruitmentAccessService;
use App\Domain\Hr\Services\RecruitmentService;
use App\Domain\It\Services\ItSlaReadService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\SecurityDevices\Management\Services\CommandObservationFreshnessService;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\IncidentFollowup;
use App\Models\ItChange;
use App\Models\ItTicket;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\MyDay\ShiftTaskWorkService;
use App\Services\Operations\OperationsDashboardScopeService;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Summary projections only: record access and changes stay with each owning module. */
final class OverviewData
{
    private array $blocks = [];

    private array $attention = [];

    private array $metrics = [];

    private ?array $teamFollowups = null;

    private bool $partial = false;

    private CarbonImmutable $start;

    private CarbonImmutable $end;

    private array $siteIds;

    private array $sites;

    private User $user;

    private string $role;

    private string $site;

    public function __construct(private readonly OverviewAccess $access, private readonly UserSiteAccessService $siteAccess) {}

    public function build(User $user, string $role, string $range, string $site): array
    {
        $this->access->authorize($user, $role);
        $this->user = $user;
        $this->role = $role;
        $this->site = $site;
        $this->sites = $this->access->sites($user, $role);
        $allowed = array_column($this->sites, 'id');
        abort_unless($site === 'all' || in_array((int) $site, $allowed, true), 403);
        $this->siteIds = $site === 'all' ? $allowed : [(int) $site];
        $today = CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
        $this->start = match ($range) {
            'week' => $today->startOfWeek(), 'month' => $today->startOfMonth(), 'quarter' => $today->startOfQuarter(), default => $today
        };
        $this->end = match ($range) {
            'week' => $this->start->addWeek(), 'month' => $this->start->addMonth(), 'quarter' => $this->start->addQuarter(), default => $today->addDay()
        };
        $this->blocks = [];
        $this->attention = [];
        $this->metrics = [];
        $this->teamFollowups = null;
        $this->partial = false;
        foreach ($this->access->catalog($role)['blocks'] as $meta) {
            $this->blocks[$meta['id']] = ['state' => 'proposed', 'rows' => [], 'series' => [], 'total' => null,
                'subtitle' => 'Proposed summary', 'message' => 'This block is planned. Its summary is not connected yet.'];
        }
        if (in_array($role, ['worker', 'lead', 'manager'], true)) {
            $this->source('Roster', fn () => $this->operations());
            $this->source('Leave', fn () => $this->leave());
            $this->source('Compliance', fn () => $this->compliance());
        }
        if ($role === 'hr') {
            $this->source('Workforce', fn () => $this->workforce());
            $this->source('Recruitment', fn () => $this->recruitment());
            $this->source('Onboarding', fn () => $this->onboarding());
            $this->source('Leave', fn () => $this->leave());
            $this->source('Compliance', fn () => $this->compliance());
        }
        if ($role === 'worker') {
            $this->source('Personal work', fn () => $this->personal());
        }
        if ($role === 'lead') {
            $this->source('Coverage', fn () => $this->teamCoverage());
            $this->source('Handovers', fn () => $this->teamHandovers());
            $approvalRows = $this->blocks['lead-approvals']['rows'] ?? [];
            $known = ($this->blocks['lead-approvals']['state'] ?? null) === 'ready';
            $this->metrics[2] = ['label' => 'Approvals', 'value' => $known ? array_sum(array_column($approvalRows, 'value')) : null, 'caption' => $known ? implode(' · ', array_map(fn ($row) => $row['value'].' '.strtolower($row['label']), $approvalRows)) : 'Approval permission required', 'target' => 'attention'];
        }
        if ($role === 'it') {
            $this->source('Service desk', fn () => $this->it());
            $this->source('Devices', fn () => $this->itDevices());
        }
        if ($role === 'governance') {
            $this->source('Governance', fn () => $this->governance());
        }
        if ($role === 'manager') {
            $this->source('Decision queue', fn () => $this->managerReviews());
        }
        $optional = match ($role) {
            'worker' => ['worker-notes' => 'drafts'],
            'lead' => ['lead-supervision' => 'supervision'],
            'manager' => ['manager-maintenance' => 'maintenance'],
            'hr' => ['hr-supervision' => 'supervision', 'hr-exits' => 'leavers'],
            'it' => ['it-knowledge' => 'knowledge'],
            'governance' => ['governance-declarations' => 'declarations'],
        };
        foreach ($optional as $id => $method) {
            $this->source('Block '.$id, fn () => $this->blocks[$id] = app(OverviewOptionalBlocks::class)->$method($user, $this->siteIds));
        }
        $connected = app(OverviewConnectedBlocks::class);
        foreach (OverviewConnectedBlocks::BLOCKS as $id) {
            if (isset($this->blocks[$id])) {
                $this->source('Block '.$id, fn () => $this->blocks[$id] = $connected->read($id, $user, $this->siteIds, $this->start, $this->end));
            }
        }
        foreach (OverviewPlanDefinitions::all() as $id => $definition) {
            if ($definition['role'] === $role) {
                $this->source('Block '.$id, function () use ($user, $id) {
                    $plans = app(OverviewPlans::class);
                    $this->blocks[$id] = $plans->summary($user, $id, $this->siteIds);
                    $attention = $plans->attention($user, $id, $this->siteIds);
                    if ($attention !== null) {
                        $this->addAttention($attention['label'], $attention['value'], $attention['href'], 'warning', $attention['detail']);
                    }
                });
            }
        }
        $this->source('Block updates', fn () => $this->blocks['updates'] = $connected->updates($user, $role, $this->siteIds));
        usort($this->attention, fn ($a, $b) => array_search($a['tone'] ?? 'neutral', ['critical', 'warning', 'neutral']) <=> array_search($b['tone'] ?? 'neutral', ['critical', 'warning', 'neutral']));
        $attentionTotal = array_sum(array_column($this->attention, 'value'));
        $decisions = $this->blocks['attention']['decisions'] ?? null;
        $reviewQueue = array_intersect_key($this->blocks['attention'], array_flip(['reviews', 'reviewTotal', 'reviewQueues']));
        $this->blocks['attention'] = $this->ready('Current work · includes overdue items outside the reporting period', null, $this->attention, $attentionTotal);
        $this->blocks['attention'] += $reviewQueue;
        $this->blocks['attention']['partial'] = $this->partial;
        if ($role === 'governance') {
            $this->blocks['attention'] += ['title' => 'Your decision brief', 'presentation' => 'decisions', 'decisions' => $decisions];
        }
        $this->metrics = array_slice($this->metrics, 0, 3);
        array_splice($this->metrics, 1, 0, [['label' => 'Needs attention', 'value' => $this->partial ? null : $attentionTotal, 'caption' => $this->partial ? 'Some sources unavailable' : 'Current priorities', 'target' => 'attention']]);
        $links = match ($role) {
            'worker' => [['My Day', '/my-day'], ['My calendar', '/my-calendar'], ['My HR', '/hr/my']],
            'lead', 'manager' => [['My Day', '/my-day'], ...($user->canDo('operations.dashboard.view') ? [['Operations', '/operations']] : [])],
            'hr' => [['HR analytics', '/hr/analytics'], ['My HR', '/hr/my']],
            'it' => [['Service desk', '/it']],
            'governance' => [['Board workspace', '/governance/dashboard'], ['My board work', '/governance/my-work']],
        };
        $this->blocks['shortcuts'] = $this->ready('Open your everyday workspaces', null, array_map(fn ($link) => ['label' => $link[0], 'href' => $link[1], 'detail' => 'Open workspace'], $links), count($links));
        foreach ($this->blocks as $id => &$block) {
            $block['scopeMode'] ??= $id === 'governance-declarations' ? 'personal' : ($role === 'governance' ? 'organisation' : (($id === 'shortcuts' || ($role === 'worker' && in_array($id, ['sites', 'worker-learning', 'worker-leave', 'worker-requests', 'worker-policy-reading', 'worker-contacts'], true))) ? 'personal' : 'site'));
        }
        unset($block);
        $this->blocks = array_intersect_key($this->blocks, array_flip(array_column($this->access->catalog($role)['blocks'], 'id')));
        $primary = match ($role) {
            'lead' => $user->canDo('shifts.manageAny') ? ['label' => 'Open team work', 'href' => '/operations/shifts'] : ['label' => 'Open My Day', 'href' => '/my-day'],
            'manager' => ['label' => 'Review decisions', 'href' => '/dashboard', 'target' => 'attention'],
            'hr' => ['label' => 'Review HR work', 'href' => '/hr/analytics'],
            'it' => ['label' => 'Open service desk', 'href' => '/it'],
            'governance' => ['label' => 'Board workspace', 'href' => '/governance/dashboard'],
            default => ['label' => 'Open My Day', 'href' => '/my-day'],
        };

        return ['primary' => $primary, 'blocks' => $this->blocks, 'metrics' => $this->metrics, 'partial' => $this->partial,
            'urgent' => array_values(array_filter($this->attention, fn ($row) => in_array($row['tone'] ?? '', ['critical', 'warning'], true))),
            'scope' => $role === 'governance' ? 'Organisation-wide board records' : ($site === 'all' ? 'All your sites' : collect($this->sites)->firstWhere('id', (int) $site)['name']),
            'period' => ['start' => $this->start->toDateString(), 'end' => $this->end->subDay()->toDateString()], 'updatedAt' => now()->toIso8601String()];
    }

    private function source(string $name, callable $read): void
    {
        try {
            $read();
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Overview source unavailable', ['source' => $name, 'viewer_id' => $this->user->id]);
            $this->partial = true;
            if ($name === 'Coverage') {
                $this->metrics = [
                    ['label' => 'Shift coverage', 'value' => null, 'caption' => 'Coverage information unavailable'],
                    ['label' => 'On duty', 'value' => null, 'caption' => 'Roster information unavailable'],
                ];
            }
            $sourceIds = match ($name) {
                'Roster', 'Workforce', 'Service desk', 'Governance' => ['focus', 'trend', 'distribution', 'sites', 'agenda'],
                'Leave' => ['worker-leave', 'lead-availability', 'hr-leave'],
                'Compliance' => ['worker-learning', 'lead-learning', 'hr-documents', ...($this->role === 'hr' ? ['distribution'] : [])],
                'Personal work' => ['worker-learning', 'worker-contacts', 'worker-requests', 'worker-policy-reading'],
                'Recruitment' => ['sites', 'hr-interviews', 'hr-recruitment-sources'],
                'Onboarding' => ['focus'],
                'Coverage' => ['trend', 'distribution', 'sites'],
                'Handovers' => ['focus', 'lead-handover'],
                'Devices' => ['sites', 'it-assets', 'it-inventory'],
                'Decision queue' => ['focus'],
                default => str_starts_with($name, 'Block ') ? [substr($name, 6)] : [],
            };
            foreach ($sourceIds as $id) {
                if (($this->blocks[$id]['state'] ?? null) === 'proposed' || in_array($name, ['Coverage', 'Handovers', 'Decision queue'], true)) {
                    $this->blocks[$id] = ['state' => 'unavailable', 'rows' => [], 'series' => [], 'total' => null, 'subtitle' => 'Information unavailable', 'message' => 'This summary could not be loaded. Refresh to try again.'];
                }
            }
            $this->attention[] = ['label' => $name.' information is unavailable', 'detail' => 'Refresh to try again. Other summaries remain available.', 'tone' => 'warning', 'status' => 'Unavailable', 'value' => 0];
        }
    }

    private function ready(string $subtitle, ?string $href, array $rows = [], ?int $total = null, array $series = []): array
    {
        return ['state' => 'ready', 'subtitle' => $subtitle, 'href' => $href, 'rows' => $rows, 'series' => $series, 'total' => $total ?? count($rows)];
    }

    private function restricted(string $id): void
    {
        $this->blocks[$id] = ['state' => 'restricted', 'subtitle' => 'Permission required', 'message' => 'This summary is not available with your current permissions.', 'rows' => [], 'series' => [], 'total' => null];
    }

    private function groups(Builder $query, string $field): array
    {
        return (clone $query)->select($field)->selectRaw('COUNT(*) AS aggregate')->groupBy($field)->orderByDesc('aggregate')->get()
            ->map(fn ($row) => ['label' => Str::headline($row->$field ?: 'Not recorded'), 'value' => (int) $row->aggregate])->all();
    }

    private function period(Builder $query, string $field): Builder
    {
        return $query->where($field, '>=', $this->start->utc())->where($field, '<', $this->end->utc());
    }

    private function days(Builder $query, string $field): array
    {
        // Convert instants in PHP so local midnight is consistent on MySQL and SQLite.
        $counts = (clone $query)->pluck($field)->countBy(fn ($value) => CarbonImmutable::parse($value)->setTimezone($this->start->timezone)->toDateString());
        $result = [];
        for ($day = $this->start; $day->lt($this->end); $day = $day->addDay()) {
            $result[] = ['label' => $day->toDateString(), 'value' => $counts->get($day->toDateString(), 0)];
        }

        return $result;
    }

    private function addAttention(string $label, int $count, string $href, string $tone = 'neutral', string $detail = 'Open the workspace to review and act'): void
    {
        if ($count > 0) {
            $action = match ($href) {
                '/operations/timesheets', '/hr/my/time' => 'Review timesheets',
                '/incidents' => str_contains($label, 'follow-up') ? 'Review follow-ups' : 'Open reviews',
                '/hr/leave' => 'Review leave',
                '/hr/compliance' => 'Review requirements',
                '/hr/my/training' => 'Open learning',
                '/governance/risks' => 'Review risks',
                '/governance/my-work' => 'Review actions',
                default => str_starts_with($href, '/it') ? 'Open support queue' : 'Review work',
            };
            $this->attention[] = ['label' => $label, 'value' => $count, 'href' => $href, 'detail' => $detail === 'Open the workspace to review and act' ? $count.' '.($count === 1 ? 'item' : 'items').' you can see' : $detail, 'tone' => $tone, 'actionLabel' => $action, 'status' => $tone === 'critical' ? 'Urgent' : ($tone === 'warning' ? 'Needs review' : 'To review')];
        }
    }

    private function operations(): void
    {
        $worker = $this->role === 'worker';
        $scope = app(OperationsDashboardScopeService::class);
        $canShifts = $worker || $this->user->canDo('shifts.manageAny') || $this->user->canDo('operations.dashboard.view');
        if (! $canShifts) {
            foreach (['focus', 'trend', 'distribution', 'sites', 'agenda'] as $id) {
                $this->restricted($id);
            }

            return;
        }
        $shifts = $scope->shifts($this->user)->whereIn('site_id', $this->siteIds);
        if ($worker) {
            $shifts = $this->siteAccess->applyShiftScope(Shift::query(), $this->user)->whereIn('site_id', $this->siteIds)->where('user_id', $this->user->id)->visibleToFrontline();
        }
        $selected = $this->period(clone $shifts, 'starts_at');
        $count = (clone $selected)->count();
        $href = $worker ? '/my-calendar' : '/operations/shifts';
        $this->blocks['trend'] = $this->ready('Scheduled shift starts · selected period', $href, [], $count, $this->days($selected, 'starts_at'));
        $this->blocks['trend']['title'] = $worker ? 'Your week ahead' : 'Shift activity';
        $this->blocks['trend']['seriesLabel'] = 'Scheduled';
        if ($worker) {
            $weekStart = CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
            $weekShifts = (clone $shifts)->where('starts_at', '>=', $weekStart->utc())->where('starts_at', '<', $weekStart->addDays(7)->utc())->where('status', '!=', 'cancelled')->orderBy('starts_at')->get(['starts_at', 'ends_at']);
            $this->blocks['trend']['calendar'] = collect(range(0, 6))->map(function ($offset) use ($weekStart, $weekShifts) {
                $day = $weekStart->addDays($offset);
                $assigned = $weekShifts->filter(fn ($shift) => $shift->starts_at->copy()->setTimezone($day->timezone)->toDateString() === $day->toDateString());

                return ['date' => $day->toDateString(), 'count' => $assigned->count(), 'hours' => round($assigned->sum(fn ($shift) => max(0, $shift->starts_at->diffInMinutes($shift->ends_at)) / 60), 1), 'starts' => $assigned->first()?->starts_at->toIso8601String(), 'ends' => $assigned->first()?->ends_at->toIso8601String()];
            })->all();
            $this->blocks['trend']['subtitle'] = 'Your assigned shifts · next seven days';
        }
        if (! $worker) {
            $completedByDay = array_column($this->days((clone $selected)->where('status', 'completed'), 'starts_at'), 'value', 'label');
            $this->blocks['trend']['series'] = array_map(fn ($point) => [...$point, 'comparison' => $completedByDay[$point['label']] ?? 0], $this->blocks['trend']['series']);
            $this->blocks['trend']['comparisonLabel'] = 'Completed';
        }
        $status = $this->groups($selected, 'status');
        $this->blocks['distribution'] = $this->ready('Recorded shift status · selected period', $href, $status, $count);
        $this->blocks['distribution']['title'] = $worker ? 'Your shift status' : 'Shift status';
        $bySite = (clone $selected)->select('site_id')->selectRaw('COUNT(*) AS aggregate')->groupBy('site_id')->pluck('aggregate', 'site_id');
        $this->blocks['sites'] = $this->ready('Scheduled shifts · selected period', $href, collect($this->sites)->whereIn('id', $this->siteIds)->map(fn ($s) => ['label' => $s['name'], 'value' => (int) ($bySite[$s['id']] ?? 0), 'href' => $href])->values()->all(), $count);
        $this->blocks['sites']['title'] = $worker ? 'Your shifts by site' : 'Your sites';
        if ($worker) {
            $this->blocks['sites'] = $this->ready('A few things to keep your week moving', null, [
                ['label' => 'Timesheets', 'detail' => 'Review your time', 'href' => '/hr/my/time'],
                ['label' => 'Leave', 'detail' => 'Plan your time off', 'href' => '/hr/my/leave'],
                ['label' => 'My learning', 'detail' => 'Continue your development', 'href' => '/hr/my/training'],
                ...($this->user->canDo('company_hub.view') ? [['label' => 'Team updates', 'detail' => 'News from your organisation', 'href' => '/company-hub']] : []),
            ]);
            $this->blocks['sites']['title'] = 'Your personal workspace';
            $this->blocks['sites']['presentation'] = 'links';
        }
        if (! $worker) {
            $completedBySite = (clone $selected)->where('status', 'completed')->select('site_id')->selectRaw('COUNT(*) AS aggregate')->groupBy('site_id')->pluck('aggregate', 'site_id');
            $this->blocks['sites']['rows'] = collect($this->sites)->whereIn('id', $this->siteIds)->map(fn ($s) => ['label' => $s['name'], 'value' => (int) ($completedBySite[$s['id']] ?? 0), 'target' => (int) ($bySite[$s['id']] ?? 0), 'href' => $href])->values()->all();
            $this->blocks['sites']['title'] = 'Site performance';
            $this->blocks['sites']['subtitle'] = 'Completed / scheduled shifts · selected period';
        }
        $upcoming = (clone $shifts)->where('ends_at', '>=', now())->where('starts_at', '<', now()->addDays(14))->whereNotIn('status', ['cancelled', 'completed'])->with('site:id,name')->orderBy('starts_at')->limit(6)->get(['id', 'site_id', 'starts_at', 'ends_at', 'status']);
        $rows = $upcoming->map(fn ($s) => ['label' => $s->site?->name ?? 'Assigned shift', 'date' => $s->starts_at->toIso8601String(), 'end' => $s->ends_at->toIso8601String(), 'detail' => 'Open the roster for shift details', 'status' => Str::headline($s->status), 'href' => $worker ? '/my-day' : $href])->all();
        $this->blocks['agenda'] = $this->ready('Next 14 days · next six shifts', $href, $rows);
        $this->blocks['focus'] = $this->ready($worker ? 'Continue your care work in My Day' : 'Upcoming rostered work', $worker ? '/my-day' : $href, array_slice($rows, 0, 1));
        $this->blocks['focus']['title'] = $worker ? 'Your next shift' : 'The next shift';
        $this->blocks['focus']['actionLabel'] = $worker ? 'Open My Day' : 'Open team roster';
        $this->blocks['focus']['empty'] = $worker ? 'Nothing is rostered for you in the next 14 days. New shifts appear here once they are published.' : 'No shifts are rostered in the next 14 days.';
        if (! $rows) {
            $this->blocks['focus']['headline'] = 'No shift scheduled';
        }
        $this->metrics[] = ['label' => $worker ? 'Your shifts' : 'Scheduled shifts', 'value' => $count, 'caption' => 'Selected reporting period', 'href' => $href, 'series' => array_column($this->blocks['trend']['series'], 'value')];
        $completed = (clone $selected)->where('status', 'completed')->count();
        $this->metrics[] = ['label' => 'Completed shifts', 'value' => $completed, 'total' => $count, 'caption' => 'Of shifts in this period', 'href' => $href];
        if ($worker) {
            $this->workerProgress($shifts);
        }
        if (! $worker) {
            $this->metrics[] = ['label' => 'Sites in view', 'value' => count($this->siteIds), 'caption' => 'Approved sites in this filter', 'href' => $href];
        }
        if ($this->role === 'manager') {
            $this->managerDelivery($selected, $href);
            $this->restricted('distribution');
            $this->restricted('agenda');
        }
        if ($worker || $this->user->canDo('timesheets.manageAny')) {
            $timesheets = $worker ? $this->siteAccess->applyTimesheetScope(Timesheet::query(), $this->user)->where('user_id', $this->user->id) : $scope->timesheets($this->user);
            $timesheets->whereIn('site_id', $this->siteIds);
            $pending = (clone $timesheets)->whereIn('status', $worker ? ['draft', 'returned'] : ['submitted'])->count();
            $sheetHref = $worker ? '/hr/my/time' : '/operations/timesheets';
            $this->addAttention($worker ? 'Timesheets to finish' : 'Timesheets ready for review', $pending, $sheetHref);
            $this->blocks['lead-approvals'] = $this->ready('Current timesheet queue', $sheetHref, [['label' => 'Timesheets', 'value' => $pending]], $pending);
            if ($worker) {
                $this->metrics[] = ['label' => 'Timesheet', 'value' => $pending, 'caption' => 'Drafts and returns to review', 'href' => $sheetHref];
            }
        }
        if (! $worker && ($this->user->canDo('incidents.viewAny') || $this->user->canDo('incidents.viewAssigned'))) {
            $incidents = $scope->incidents($this->user)->whereHas('client', fn ($q) => $q->whereIn('site_id', $this->siteIds));
            if (! $this->user->canDo('incidents.viewAny')) {
                $incidents->whereIn('client_id', $this->user->assignedClients()->select('clients.id'));
            }
            $followups = IncidentFollowup::query()->whereIn('client_incident_id', (clone $incidents)->select('client_incidents.id'));
            $late = (clone $followups)->whereNull('completed_at')->where('due_at', '<', now())->count();
            $serious = (clone $incidents)->whereNull('reviewed_at')->whereIn('severity', ['high', 'critical'])->count();
            $this->addAttention('Serious incident reviews', $serious, '/incidents', 'critical');
            $this->addAttention('Overdue incident follow-ups', $late, '/incidents', 'warning');
            if ($this->role === 'lead') {
                $this->teamFollowups = (clone $followups)->whereNull('completed_at')
                    ->with('incident:id,client_id', 'incident.client:id,site_id')
                    ->get(['id', 'client_incident_id', 'due_at'])->groupBy(fn ($item) => $item->incident->client->site_id)
                    ->map(fn ($items) => ['count' => $items->count(), 'overdue' => $items->filter(fn ($item) => $item->due_at?->isPast())->count()])->all();
            }
            if ($this->role === 'manager') {
                $periodFollowups = $this->period(clone $followups, 'due_at');
                $all = (clone $periodFollowups)->count();
                $done = (clone $periodFollowups)->whereNotNull('completed_at')->count();
                $periodLate = (clone $periodFollowups)->whereNull('completed_at')->where('due_at', '<', now())->count();
                $this->blocks['distribution'] = $this->ready('Incident actions · due in selected period', '/incidents', [['label' => 'Closed', 'value' => $done], ['label' => 'Open', 'value' => max(0, $all - $done - $periodLate)], ['label' => 'Overdue', 'value' => $periodLate]], $all);
                $this->blocks['distribution']['title'] = 'Follow-up status';
                $this->blocks['distribution']['footnote'] = 'Live incident actions · due in selected period';
                $this->metrics[2] = ['label' => 'Follow-ups closed', 'value' => $done, 'total' => $all, 'caption' => ($all - $done).' still open in this period', 'instrument' => 'donut', 'instrumentCaption' => 'Recorded complete', 'href' => '/incidents'];
                $due = (clone $followups)->whereNull('completed_at')->whereNotNull('due_at')->where('due_at', '<', now()->addDays(14))->orderBy('due_at')->limit(6)->get(['id', 'due_at']);
                $this->blocks['agenda'] = $this->ready('Incident follow-ups · overdue and next 14 days', '/incidents', $due->map(fn ($row) => ['label' => 'Incident follow-up review', 'date' => $row->due_at->toIso8601String(), 'detail' => 'Open the incident for its owner and context', 'status' => $row->due_at->isPast() ? 'Overdue' : 'Upcoming', 'tone' => $row->due_at->isPast() ? 'warning' : 'neutral', 'href' => '/incidents'])->all());
                $this->blocks['agenda']['title'] = 'Decisions and reviews';
                $this->blocks['agenda']['footnote'] = 'Next review dates · includes overdue work';
            }
        }
    }

    /** The same task and client visibility as My Day, without exposing task or client names. */
    private function workerProgress(Builder $shifts): void
    {
        $today = CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
        $todayShifts = (clone $shifts)->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $today->addDay()->utc())->where('ends_at', '>', $today->utc())
            ->with('tasks:id,shift_id,task_scope,client_id,is_completed,help_status')->get();
        $work = app(ShiftTaskWorkService::class);
        $tasks = $todayShifts->flatMap(function (Shift $shift) use ($work) {
            $clientIds = $work->availableClients($this->user, $shift)->modelKeys();

            return $shift->tasks->filter(fn ($task) => $task->task_scope === 'site'
                || ! ($task->client_id ?? $shift->client_id)
                || in_array((int) ($task->client_id ?? $shift->client_id), $clientIds, true));
        });
        $total = $tasks->count();
        $done = $tasks->where('is_completed', true)->count();
        $waiting = $tasks->where('is_completed', false)->whereIn('help_status', ['requested', 'accepted'])->count();
        $this->blocks['distribution'] = $this->ready('Tasks on your shifts today · includes overnight shifts', '/my-day', [
            ['label' => 'Done', 'value' => $done], ['label' => 'Still to do', 'value' => $total - $done - $waiting], ['label' => 'Waiting', 'value' => $waiting],
        ], $total);
        $this->blocks['distribution']['title'] = 'Your shift progress';
        $this->blocks['distribution']['footnote'] = 'Waiting for help · your tasks';
        $week = (clone $shifts)->where('status', '!=', 'cancelled')
            ->where('starts_at', '>=', $today->startOfWeek()->utc())->where('starts_at', '<', $today->startOfWeek()->addWeek()->utc())
            ->get(['starts_at', 'ends_at']);
        $hours = round($week->sum(fn ($shift) => max(0, $shift->starts_at->diffInMinutes($shift->ends_at)) / 60), 1);
        $this->metrics = [
            ['label' => 'Shift tasks', 'value' => $done, 'display' => $done.' of '.$total, 'instrument' => 'bar', 'percent' => $total ? round($done / $total * 100) : null, 'caption' => $total ? ($total - $done).' still to do today' : 'No tasks on your shifts today', 'href' => '/my-day'],
            ['label' => 'This week', 'value' => $hours, 'display' => $hours.'h', 'caption' => $week->count().' scheduled shifts · before breaks', 'href' => '/my-calendar'],
        ];
        $first = $this->blocks['focus']['rows'][0] ?? null;
        if ($first) {
            $current = CarbonImmutable::parse($first['date'])->lte(now());
            $this->blocks['focus']['title'] = $current ? 'Your current shift' : 'Your next shift';
            $this->blocks['focus']['focusTag'] = $current ? 'Rostered now' : 'Coming up';
            $this->blocks['focus']['actionLabel'] = $current ? 'Continue in My Day' : 'Open My Day';
            $this->blocks['focus']['rows'][0]['detail'] = $current ? 'Your handover and care tasks are in My Day.' : 'Review your next shift and prepare in My Day.';
        }
    }

    /** Delivery is assessed only once a planned shift has ended; future work is not a failure. */
    private function managerDelivery(Builder $selected, string $href): void
    {
        $records = (clone $selected)->where('status', '!=', 'cancelled')->where('ends_at', '<=', now())->get(['site_id', 'starts_at', 'status']);
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $seriesFor = function ($records) use ($timezone): array {
            $days = $records->groupBy(fn ($shift) => $shift->starts_at->copy()->setTimezone($timezone)->toDateString());
            $series = [];
            for ($day = $this->start; $day->lt($this->end); $day = $day->addDay()) {
                $shifts = $days->get($day->toDateString(), collect());
                $planned = $shifts->count();
                $completed = $shifts->where('status', 'completed')->count();
                $series[] = ['label' => $day->toDateString(), 'value' => $planned ? round($completed / $planned * 100, 1) : null, 'comparison' => $planned ? 100 : null, 'completed' => $completed, 'planned' => $planned];
            }

            return $series;
        };
        $planned = $records->count();
        $completed = $records->where('status', 'completed')->count();
        $percent = $planned ? round($completed / $planned * 100) : null;
        $this->blocks['trend'] = $this->ready('Delivered shifts against planned shifts', $href, [], $planned, $seriesFor($records));
        $this->blocks['trend'] += ['title' => 'Service delivery', 'unit' => '%', 'seriesLabel' => 'Delivered', 'comparisonLabel' => 'Planned', 'footnote' => 'Completed / planned · ended shifts only · cancelled excluded'];
        $rows = collect($this->sites)->whereIn('id', $this->siteIds)->map(function ($site) use ($records, $seriesFor, $href) {
            $shifts = $records->where('site_id', $site['id']);
            $target = $shifts->count();
            $value = $shifts->where('status', 'completed')->count();

            return ['label' => $site['name'], 'value' => $value, 'target' => $target, 'detail' => ! $target ? 'No ended shifts in this period' : ($value === $target ? 'All planned shifts completed' : ($target - $value).' shifts not recorded complete'), 'sparkline' => array_column($seriesFor($shifts), 'value'), 'href' => $href.'?site_id='.$site['id']];
        })->values()->all();
        $this->blocks['sites'] = $this->ready('Delivered shifts · selected reporting period', $href, $rows, $planned);
        $this->blocks['sites'] += ['title' => 'Site performance', 'presentation' => 'delivery', 'footnote' => 'Ended shifts only · cancelled shifts excluded'];
        $this->metrics = [
            ['label' => 'Service delivery', 'value' => $percent, 'display' => $percent === null ? '—' : $percent.'%', 'instrument' => 'bar', 'percent' => $percent, 'caption' => $planned ? $completed.' of '.$planned.' ended shifts completed' : 'No ended shifts in this period', 'href' => $href],
            ['label' => 'Sites in view', 'value' => count($this->siteIds), 'caption' => 'Sites you can see', 'href' => $href],
            ['label' => 'Follow-ups closed', 'value' => null, 'caption' => 'Incident review permission required', 'target' => 'attention'],
        ];
    }

    private function workforceQuery(): Builder
    {
        $query = $this->siteAccess->applyCurrentHrEmployeeProfileScope(HrEmployeeProfile::query(), $this->user);

        return $query->whereIn('primary_site_id', $this->siteIds);
    }

    private function managerReviews(): void
    {
        $scope = app(OperationsDashboardScopeService::class);
        $rows = collect();
        $total = 0;
        $sources = 0;
        $queues = [];
        if (($this->user->canDo('timesheets.approve') || $this->user->canDo('timesheets.manageAny'))
            && ($this->user->canDo('timesheets.viewAny') || $this->user->canDo('timesheets.viewAssigned'))) {
            // Review capability does not grant the dashboard's optional global Site bypass.
            $query = $this->siteAccess->applyTimesheetScope($scope->timesheets($this->user), $this->user, [])
                ->whereIn('site_id', $this->siteIds)->where('status', 'submitted')->whereNull('archived_at');
            $count = (clone $query)->count();
            $total += $count;
            $queues[] = ['label' => 'Timesheet reviews', 'value' => $count, 'href' => '/operations/timesheets?tab=submitted'];
            $this->attention = array_values(array_filter($this->attention, fn ($row) => $row['label'] !== 'Timesheets ready for review'));
            $this->addAttention('Timesheets ready for review', $count, '/operations/timesheets?tab=submitted', 'neutral');
            $sources++;
            $rows = $rows->concat($query->with('site:id,name')->orderBy('submitted_at')->orderBy('id')->limit(6)->get(['id', 'site_id', 'work_date', 'submitted_at'])
                ->map(fn ($sheet) => ['label' => 'Timesheet #'.$sheet->id, 'detail' => ($sheet->site?->name ?? 'Permitted site').' · Worked '.$sheet->work_date->format('j M'), 'status' => 'Submitted', 'href' => '/operations/timesheets/'.$sheet->id, 'sort' => $sheet->submitted_at?->toIso8601String() ?? '', 'priority' => 2]));
        }
        $leave = app(HrLeaveAccessService::class);
        if ($leave->canReviewLeave($this->user)) {
            $query = $leave->visibleRequests($this->user, true)->where('status', 'pending')
                ->whereIn('user_id', $leave->currentStaffQuery($this->user)->select('users.id'))
                ->whereHas('user.hrEmployeeProfile', fn ($profile) => $this->selectedStaffSites($profile));
            $count = (clone $query)->count();
            $total += $count;
            $queues[] = ['label' => 'Leave reviews', 'value' => $count, 'href' => '/hr/leave'];
            $sources++;
            $rows = $rows->concat($query->orderByRaw('approval_due_at IS NULL')->orderBy('approval_due_at')->orderBy('id')->limit(6)->get(['id', 'starts_at', 'ends_at', 'approval_due_at'])
                ->map(fn ($request) => ['label' => 'Leave request #'.$request->id, 'detail' => $request->starts_at->copy()->setTimezone($this->start->timezone)->format('j M').' – '.$request->ends_at->copy()->setTimezone($this->start->timezone)->format('j M').' · Open request for details', 'date' => $request->approval_due_at?->toIso8601String(), 'status' => $request->approval_due_at?->isPast() ? 'Review overdue' : 'Awaiting review', 'tone' => $request->approval_due_at?->isPast() ? 'warning' : 'neutral', 'href' => '/hr/leave/'.$request->id, 'sort' => $request->approval_due_at?->toIso8601String() ?? '9999', 'priority' => $request->approval_due_at?->isPast() ? 1 : 2]));
        }
        if ($this->user->canDo('incidents.approve') && ($this->user->canDo('incidents.viewAny') || $this->user->canDo('incidents.viewAssigned'))) {
            $query = $this->siteAccess->applyClientIncidentScope($scope->incidents($this->user), $this->user, ['sites.viewAll', 'incidents.manage'])
                ->whereHas('client', fn ($client) => $client->whereIn('site_id', $this->siteIds))->where('status', 'submitted');
            if (! $this->user->canDo('incidents.viewAny')) {
                $query->whereIn('client_id', $this->user->assignedClients()->select('clients.id'));
            }
            $count = (clone $query)->count();
            $total += $count;
            $queues[] = ['label' => 'Incident reviews', 'value' => $count, 'href' => '/incidents?tab=review'];
            $this->addAttention('Other incident reviews', (clone $query)->whereNotIn('severity', ['high', 'critical'])->count(), '/incidents?tab=review', 'neutral');
            $sources++;
            $rows = $rows->concat($query->orderByRaw("CASE WHEN severity IN ('high', 'critical') THEN 0 ELSE 1 END")->orderBy('submitted_at')->orderBy('id')->limit(6)->get(['id', 'severity', 'submitted_at'])
                ->map(fn ($incident) => ['label' => 'Incident #'.$incident->id.' review', 'detail' => 'Submitted for review · open the record for context', 'status' => Str::headline($incident->severity).' severity', 'tone' => in_array($incident->severity, ['high', 'critical'], true) ? 'critical' : 'neutral', 'href' => '/incidents/'.$incident->id, 'sort' => $incident->submitted_at?->toIso8601String() ?? '', 'priority' => in_array($incident->severity, ['high', 'critical'], true) ? 0 : 2]));
        }
        $reviews = $rows->sortBy([['priority', 'asc'], ['sort', 'asc']])->take(6)->map(fn ($row) => array_diff_key($row, ['priority' => true, 'sort' => true]))->values()->all();
        $this->blocks['attention']['reviews'] = $reviews;
        $this->blocks['attention']['reviewTotal'] = $total;
        $this->blocks['attention']['reviewQueues'] = array_values(array_filter($queues, fn ($queue) => $queue['value'] > 0));
        $this->blocks['focus'] = $this->ready('Specific requests you can open and review', '/dashboard', array_slice($reviews, 0, 1), $total);
        $this->blocks['focus'] += ['title' => 'Your decision queue', 'actionLabel' => 'Review decision queue', 'actionTarget' => 'attention', 'headline' => $total ? $total.' '.($total === 1 ? 'review' : 'reviews') : 'Nothing to review', 'focusTag' => 'Ready for review', 'focusMeta' => [$sources.' review '.Str::plural('queue', $sources), count($this->siteIds).' '.Str::plural('site', count($this->siteIds)).' in view'], 'empty' => $sources ? 'No submitted decisions are waiting for your review.' : 'Review permissions are required to see decision requests.'];
        if (! $sources) {
            $this->blocks['focus']['state'] = 'restricted';
            $this->blocks['focus']['message'] = $this->blocks['focus']['empty'];
        }
    }

    private function selectedStaffSites(Builder $profile): void
    {
        $profile->where(function ($site) {
            $site->whereIn('primary_site_id', $this->siteIds);
            foreach ($this->siteIds as $id) {
                $site->orWhereJsonContains('secondary_site_ids', $id);
            }
        });
    }

    private function teamCoverage(): void
    {
        if (! $this->user->canDo('shifts.manageAny') && ! $this->user->canDo('operations.dashboard.view')) {
            return;
        }
        $coverage = app(OverviewCoverage::class)->read($this->user, $this->sites, $this->siteIds);
        $href = $this->user->canDo('rostering.viewAny') ? '/operations/rostering' : ($this->user->canDo('shifts.viewAny') || $this->user->canDo('shifts.manageAny') ? '/operations/shifts' : '/operations');
        $need = $coverage['required'];
        $assigned = $coverage['assigned'];
        $known = $need !== null && $need > 0;
        $this->blocks['trend'] = $this->ready('Today · see where recorded staffing needs attention', $href, [], $coverage['windows']);
        $this->blocks['trend'] += ['title' => 'Coverage at a glance', 'coverage' => $coverage, 'footnote' => 'Recorded requirements · lowest assigned staffing per window'];
        $this->blocks['distribution'] = $this->ready('Assigned positions against recorded staffing requirements', $href, [['label' => 'Assigned', 'value' => $assigned ?? 0], ['label' => 'Unfilled', 'value' => max(0, ($need ?? 0) - ($assigned ?? 0))]], $need ?? 0);
        $this->blocks['distribution'] += ['title' => 'Today’s shift coverage', 'footnote' => 'Role gaps are highlighted on the coverage board'];
        if ($coverage['unassessed']) {
            $this->blocks['distribution'] = ['state' => 'unavailable', 'subtitle' => 'Coverage needs review', 'message' => 'Some contributing roster records could not be assessed with your access.', 'rows' => [], 'series' => [], 'total' => null];
        }
        $scope = app(OperationsDashboardScopeService::class);
        $dutyShifts = $scope->shifts($this->user)->whereIn('site_id', $this->siteIds)
            ->where('status', 'in_progress')->where('actual_starts_at', '<=', now())->whereNull('actual_ends_at');
        $onDuty = (clone $dutyShifts)->distinct()->count('user_id');
        $dutyBySite = (clone $dutyShifts)->select('site_id')->selectRaw('COUNT(DISTINCT user_id) AS aggregate')->groupBy('site_id')->pluck('aggregate', 'site_id');
        $onDutyStaff = $scope->staff($this->user)->whereIn('users.id', (clone $dutyShifts)->select('user_id'))->orderBy('name')->limit(3)->get(['users.id', 'users.name']);
        $initials = $onDutyStaff->pluck('name')
            ->map(fn ($name) => collect(preg_split('/\s+/u', trim($name)))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode(''))->all();
        $people = $onDutyStaff->map(fn ($staff) => ['id' => $staff->id, 'name' => $staff->name, 'detail' => 'On shift now'])->all();
        $this->metrics = [
            ['label' => 'Shift coverage', 'value' => $known ? $assigned : null, 'display' => $known ? $assigned.' / '.$need : '—', 'instrument' => 'bar', 'percent' => $known ? round($assigned / $need * 100) : null, 'caption' => ! $known ? ($coverage['unassessed'] ? 'Some coverage cannot be assessed' : 'No staffing requirements recorded') : ($coverage['gaps'] ? $coverage['gaps'].' windows need staffing review' : 'Assigned positions / recorded requirements'), 'href' => $href],
            ['label' => 'On duty', 'value' => $onDuty, 'caption' => 'Started shifts · not yet ended', 'avatars' => $initials, 'people' => $people, 'href' => $href],
        ];
        $this->addAttention('Staffing windows need cover', $coverage['gaps'], $href, 'critical', 'Review the gaps and role cover shown on your coverage board.');
        if ($coverage['unassessed']) {
            $this->addAttention('Coverage needs assessment', $coverage['unassessed'], $href, 'warning', 'Some roster records are outside what you can assess.');
        }
        $this->blocks['sites'] = $this->ready('Current staffing, upcoming handovers and open incident actions', $href, collect($coverage['rows'])->map(function ($site) use ($dutyBySite) {
            $windows = collect($site['cells'])->flatten(1);
            $unknown = $windows->whereNull('assigned')->count();
            $gaps = $windows->where('gap', true)->count();

            $followups = $this->teamFollowups[$site['id']] ?? ['count' => 0, 'overdue' => 0];

            return ['siteId' => $site['id'], 'label' => $site['name'], 'detail' => ($dutyBySite[$site['id']] ?? 0).' on duty', 'status' => $unknown ? 'Check coverage' : ($gaps ? 'Cover needed' : ($windows->isEmpty() ? 'No requirements' : 'Staffing matched')), 'tone' => $gaps || $unknown ? 'warning' : 'neutral', 'href' => app(OverviewCoverage::class)->href($this->user, $site['id'], CarbonImmutable::parse($this->blocks['trend']['coverage']['date'], $this->start->timezone)),
                'followups' => $this->teamFollowups === null ? ['label' => 'Permission required'] : ['label' => $followups['count'].' open', 'detail' => $followups['overdue'] ? $followups['overdue'].' overdue' : 'Incident follow-ups', 'tone' => $followups['overdue'] ? 'warning' : 'neutral', 'href' => '/incidents?tab=followups&site_id='.$site['id']],
                'handover' => ['label' => 'Information unavailable']];
        })->all());
        $this->blocks['sites'] += ['title' => 'Your teams, connected', 'presentation' => 'connected-teams'];
    }

    private function teamHandovers(): void
    {
        $workflow = app(ShiftHandoverService::class);
        if (! $workflow->canAccessWorkflow($this->user)) {
            $this->restricted('focus');
            $this->restricted('lead-handover');
            foreach ($this->blocks['sites']['rows'] as &$site) {
                $site['handover'] = ['label' => 'Permission required'];
            }
            unset($site);

            return;
        }
        $query = $this->siteAccess->applyHandoverScope(ShiftHandover::query(), $this->user, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS)
            ->whereHas('outgoingShift', fn ($shift) => $shift->whereIn('site_id', $this->siteIds))
            ->whereHas('incomingShift', fn ($shift) => $shift->where('starts_at', '>=', now())->where('starts_at', '<', now()->addDays(14))->whereNotIn('status', ['completed', 'cancelled']));
        if (! $workflow->canViewAny($this->user)) {
            $query->where(fn ($related) => $related->where('outgoing_staff_id', $this->user->id)->orWhereHas('outgoingShift', fn ($shift) => $shift->where('user_id', $this->user->id))->orWhereHas('incomingShift', fn ($shift) => $shift->where('user_id', $this->user->id)));
        }
        $handovers = $query->with('incomingShift:id,site_id,user_id,starts_at', 'incomingShift.site:id,name')->get(['id', 'incoming_shift_id', 'status'])->sortBy(fn ($handover) => $handover->incomingShift->starts_at);
        foreach ($this->blocks['sites']['rows'] as &$site) {
            $next = $handovers->first(fn ($handover) => $handover->incomingShift->site_id === $site['siteId']);
            $site['handover'] = $next ? ['label' => Str::headline($next->status), 'date' => $next->incomingShift->starts_at->toIso8601String(), 'href' => '/operations/handovers/'.$next->id] : ['label' => 'None recorded', 'detail' => 'Next 14 days'];
        }
        unset($site);
        $rows = $handovers->take(6)->map(fn ($handover) => ['label' => ($handover->incomingShift->site?->name ?? 'Site').' handover', 'date' => $handover->incomingShift->starts_at->toIso8601String(), 'status' => Str::headline($handover->status), 'detail' => 'Review the recorded handover before the incoming shift.', 'href' => '/operations/handovers/'.$handover->id])->values()->all();
        $href = '/operations/handovers';
        $this->blocks['lead-handover'] = $this->ready('Recorded handovers · next 14 days', $href, $rows, $handovers->count());
        $this->blocks['focus'] = $this->ready('Next recorded handover across your teams', $href, array_slice($rows, 0, 1));
        $this->blocks['focus'] += ['title' => 'The next handover', 'focusTag' => 'Handover', 'actionLabel' => 'Review handover', 'emptyActionLabel' => 'Open handovers', 'empty' => 'No handover is recorded for your teams in the next 14 days.'];
        if (! $rows) {
            $this->blocks['focus']['headline'] = 'No handover due';
        }
        $this->blocks['agenda'] = $this->ready('Upcoming recorded handovers and rostered shifts', $href, collect([...$rows, ...($this->blocks['agenda']['rows'] ?? [])])->sortBy('date')->take(6)->values()->all());
    }

    private function workforce(): void
    {
        $query = $this->workforceQuery();
        $count = (clone $query)->count();
        $href = $this->user->canDo('hr.employees.viewAny') ? '/hr/employees' : '/hr/analytics';
        $this->metrics = [
            ['label' => 'Active workforce', 'value' => $count, 'caption' => 'Approved staff · primary site', 'href' => $href],
            ['label' => 'Training current', 'value' => null, 'caption' => 'Compliance permission required', 'href' => $href],
            ['label' => 'Open vacancies', 'value' => null, 'caption' => 'Recruitment permission required', 'href' => $href],
        ];
        $groups = $this->groups($query, 'employment_type');
        $this->blocks['hr-workforce'] = $this->ready('Current workforce · primary site', $href, $groups, $count);
        // Retained employment dates are the historical source; today's active picker would hide leavers.
        $history = $this->siteAccess->applyHistoricalHrEmployeeProfileScope(HrEmployeeProfile::withTrashed(), $this->user)
            ->whereIn('primary_site_id', $this->siteIds)->whereNotNull('start_date')->get(['start_date', 'end_date']);
        $today = CarbonImmutable::now($this->start->timezone)->startOfDay();
        $series = [];
        for ($day = $this->start; $day->lt($this->end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $series[] = ['label' => $date, 'value' => $day->gt($today) ? null : $history->filter(fn ($profile) => $profile->start_date->toDateString() <= $date && (! $profile->end_date || $profile->end_date->toDateString() >= $date))->count()];
        }
        $this->blocks['trend'] = $this->ready('Recorded employment dates · selected period', $href, [], $count, $series);
        $this->blocks['trend'] += ['title' => 'Workforce movement', 'seriesLabel' => 'Headcount', 'footnote' => 'Retained employment dates · future dates not measured'];
        $probation = (clone $query)->whereNotNull('probation_end_date')->whereBetween('probation_end_date', [today(), today()->addDays(30)]);
        $n = (clone $probation)->count();
        $rows = $probation->orderBy('probation_end_date')->limit(6)->get(['id', 'probation_end_date'])->map(fn ($p) => ['label' => 'Probation review', 'date' => $p->probation_end_date->toDateString(), 'detail' => 'Open the employee record for detail', 'href' => $href])->all();
        $this->blocks['hr-probation'] = $this->ready('Reviews due in the next 30 days', $href, $rows, $n);
        $this->blocks['agenda'] = $this->blocks['hr-probation'];
        $this->blocks['agenda']['title'] = 'People deadlines';
        $this->addAttention('Probation reviews coming up', $n, $href);
    }

    private function mergePeopleDeadlines(array $rows): void
    {
        $all = collect([...($this->blocks['agenda']['rows'] ?? []), ...$rows])->filter(fn ($row) => ! empty($row['date']))->sortBy('date')->values();
        $this->blocks['agenda'] = $this->ready('Next recorded people deadlines · your records', null, $all->take(6)->all(), $all->count());
        $this->blocks['agenda']['title'] = 'People deadlines';
    }

    private function recruitment(): void
    {
        if (! $this->user->canDo('hr.recruitment.view')) {
            foreach (['sites', 'hr-interviews', 'hr-recruitment-sources'] as $id) {
                $this->restricted($id);
            }

            return;
        }
        $access = app(HrRecruitmentAccessService::class);
        $applications = $access->visibleApplications($this->user)->whereIn('target_site_id', $this->siteIds);
        $candidates = $access->visibleCandidates($this->user)->whereIn('id', (clone $applications)->select('candidate_id'))->whereNotIn('status', RecruitmentService::TERMINAL);
        $counts = (clone $candidates)->select('status')->selectRaw('COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $stages = ['New' => ['new'], 'Screening' => ['screening'], 'Interview' => ['interview_scheduled', 'interview_completed'], 'References' => ['reference_check'], 'Offer' => ['offer_pending', 'offer_sent'], 'Joining' => ['offer_accepted', 'onboarding']];
        $href = '/hr/recruitment?tab=board';
        $rows = collect($stages)->map(fn ($keys, $label) => ['label' => $label, 'value' => collect($keys)->sum(fn ($key) => (int) ($counts[$key] ?? 0)), 'href' => $href])->values()->all();
        $this->blocks['sites'] = $this->ready('Active candidates · your sites', $href, $rows, array_sum(array_column($rows, 'value')));
        $this->blocks['sites'] += ['title' => 'Recruitment pipeline', 'presentation' => 'pipeline', 'footnote' => 'Current stage · each candidate counted once'];
        $this->blocks['hr-recruitment-sources'] = $this->ready('Active candidates by recorded source', '/hr/recruitment?tab=analytics', $this->groups($candidates, 'source'), (clone $candidates)->count());
        $vacancies = $access->visibleRequisitions($this->user)->whereIn('site_id', $this->siteIds)->where('status', 'published');
        $this->metrics[2] = ['label' => 'Open vacancies', 'value' => (clone $vacancies)->count(), 'caption' => 'Published job adverts', 'href' => '/hr/recruitment?tab=requisitions'];
        $interviews = HrInterview::query()->whereIn('application_id', (clone $applications)->select('id'))->where('status', 'scheduled')->where('scheduled_at', '>=', now())->where('scheduled_at', '<', now()->addDays(30));
        $upcoming = (clone $interviews)->orderBy('scheduled_at')->limit(6)->get(['id', 'scheduled_at'])->map(fn ($interview) => ['label' => 'Candidate interview', 'date' => $interview->scheduled_at->toIso8601String(), 'status' => 'Scheduled', 'href' => '/hr/recruitment?tab=interviews'])->all();
        $this->blocks['hr-interviews'] = $this->ready('Scheduled interviews · next 30 days', '/hr/recruitment?tab=interviews', $upcoming, (clone $interviews)->count());
        $this->mergePeopleDeadlines($upcoming);
        $offers = $access->visibleOffers($this->user)->whereIn('application_id', (clone $applications)->select('id'))->where('approval_status', 'pending_approval')->whereNull('sent_at')->whereNull('response')->count();
        $this->addAttention('Offers awaiting approval', $offers, '/hr/recruitment?tab=offers');
    }

    private function onboarding(): void
    {
        if (! $this->user->canDo('hr.onboarding.view')) {
            $this->restricted('focus');

            return;
        }
        $access = app(HrLifecycleAccessService::class);
        $today = CarbonImmutable::now($this->start->timezone)->startOfDay();
        $profiles = $access->onboardingProfiles($this->user)->whereIn('primary_site_id', $this->siteIds)
            ->whereBetween('start_date', [$today->toDateString(), $today->addDays(14)->toDateString()])->get(['id', 'start_date']);
        $checklists = $access->visibleOnboardingChecklists($this->user)->whereIn('employee_profile_id', $profiles->modelKeys())->whereNotIn('status', ['cancelled', 'archived'])
            ->withCount(['tasks', 'tasks as open_tasks_count' => fn ($query) => $query->where('status', '!=', 'completed')])->get()->groupBy('employee_profile_id');
        $complete = $profiles->filter(function ($profile) use ($checklists) {
            $lists = $checklists->get($profile->id, collect());

            return $lists->isNotEmpty() && $lists->every(fn ($list) => $list->tasks_count > 0 && $list->open_tasks_count === 0);
        })->count();
        $count = $profiles->count();
        $href = '/hr/onboarding';
        $this->blocks['focus'] = $this->ready('Starting over the next 14 days', $href, $count ? [['label' => 'Starting over the next 14 days', 'detail' => 'Open onboarding for the people, owners and remaining steps.', 'href' => $href]] : [], $count);
        $this->blocks['focus'] += ['title' => 'Joining the team', 'headline' => $count ? $count.' new '.($count === 1 ? 'starter' : 'starters') : 'No new starters', 'emptyActionLabel' => 'Open onboarding', 'focusTag' => 'Onboarding', 'focusMeta' => [$complete.' '.Str::plural('checklist', $complete).' complete', ($count - $complete).' '.($count - $complete === 1 ? 'needs' : 'need').' preparation'], 'actionLabel' => 'Review onboarding', 'empty' => 'No new starters are recorded in the next 14 days.'];
        $this->addAttention('New starters need onboarding', $count - $complete, $href, 'warning');
        $this->mergePeopleDeadlines($profiles->sortBy('start_date')->take(6)->map(fn ($profile) => ['label' => 'New starter begins', 'date' => $profile->start_date->toDateString(), 'status' => 'Start date', 'href' => $href])->all());
    }

    private function leave(): void
    {
        $worker = $this->role === 'worker';
        if (! $worker && ! app(HrLeaveAccessService::class)->canReviewLeave($this->user)) {
            return;
        }
        $query = app(HrLeaveAccessService::class)->visibleRequests($this->user, ! $worker);
        if (! $worker) {
            $query->whereHas('user.hrEmployeeProfile', fn ($profile) => $this->selectedStaffSites($profile));
        }
        $href = $worker ? '/hr/my/leave' : '/hr/leave';
        $pending = (clone $query)->where('status', 'pending')->count();
        if (! $worker) {
            $this->addAttention('Leave requests awaiting review', $pending, $href);
            if ($this->role === 'lead') {
                $approvalRows = $this->blocks['lead-approvals']['state'] === 'ready' ? $this->blocks['lead-approvals']['rows'] : [];
                $this->blocks['lead-approvals'] = $this->ready('Current approval queues', null, [...$approvalRows, ['label' => 'Leave requests', 'value' => $pending, 'href' => $href]], array_sum(array_column($approvalRows, 'value')) + $pending);
            }
        }
        $rows = (clone $query)->where('ends_at', '>=', now())->orderBy('starts_at')->limit(6)->get(['id', 'starts_at', 'ends_at', 'status'])
            ->map(fn ($r) => ['label' => 'Leave request', 'date' => $r->starts_at->toIso8601String(), 'end' => $r->ends_at->toIso8601String(), 'status' => Str::headline($r->status), 'detail' => 'Dates and supporting details are in the leave workspace', 'href' => $href])->all();
        $id = $worker ? 'worker-leave' : ($this->role === 'hr' ? 'hr-leave' : 'lead-availability');
        $this->blocks[$id] = $this->ready('Current and upcoming leave · next six requests', $href, $rows);
    }

    private function compliance(): void
    {
        $worker = $this->role === 'worker';
        if (! $worker && ! $this->user->canDo('hr.compliance.view')) {
            if ($this->role === 'hr') {
                $this->restricted('distribution');
            }

            return;
        }
        if ($this->role === 'hr') {
            $this->staffTraining();

            return;
        }
        $query = HrStaffComplianceStatus::query()->whereIn('user_id', $worker ? [$this->user->id] : $this->workforceQuery()->select('user_id'));
        $rows = $this->groups($query, 'status');
        $count = array_sum(array_column($rows, 'value'));
        $href = $worker ? '/hr/my/training' : '/hr/compliance';
        $id = match ($this->role) {
            'worker' => 'worker-learning', 'hr' => 'distribution', default => 'lead-learning'
        };
        $this->blocks[$id] = $this->ready($worker ? 'Your recorded compliance requirements' : 'Recorded compliance requirements · current staff', $href, $rows, $count);
        $this->blocks[$id]['title'] = $worker ? 'My requirement status' : 'Requirement status';
        $late = (clone $query)->whereIn('status', ['expired', 'non_compliant', 'missing'])->count();
        $this->addAttention('Requirements need follow-up', $late, $href, 'warning');
    }

    private function staffTraining(): void
    {
        $staff = User::query()->whereIn('id', $this->workforceQuery()->select('user_id'))->get();
        $snapshots = app(ComplianceRequirementApplicabilityService::class)->snapshotsForUsers($staff);
        $counts = ['Current' => 0, 'Due soon' => 0, 'Needs follow-up' => 0, 'Not configured' => 0];
        $renewals = [];
        foreach ($snapshots as $requirements) {
            $training = $requirements->filter(fn ($item) => $item['requirement']->category === 'training' || $item['requirement']->check_type === 'training_course');
            $bucket = $training->isEmpty() ? 'Not configured' : ($training->contains(fn ($item) => in_array($item['status'], ['expired', 'not_started'], true)) ? 'Needs follow-up' : ($training->contains(fn ($item) => $item['status'] === 'expiring_soon') ? 'Due soon' : 'Current'));
            $counts[$bucket]++;
            foreach ($requirements as $item) {
                $expiry = $item['status_row']?->expires_at;
                if ($expiry && $item['status'] === 'expiring_soon') {
                    $renewals[] = ['label' => 'Requirement renewal', 'date' => $expiry->toDateString(), 'status' => 'Due soon', 'href' => '/hr/compliance'];
                }
            }
        }
        $total = $staff->count();
        $configured = $total - $counts['Not configured'];
        $rows = collect($counts)->map(fn ($value, $label) => ['label' => $label, 'value' => $value])->values()->all();
        $this->blocks['distribution'] = $this->ready('Staff with all applicable training requirements current', '/hr/compliance', $rows, $total);
        $this->blocks['distribution'] += ['title' => 'Mandatory training', 'footnote' => 'Each staff member counted once', 'measurementKnown' => $configured > 0];
        $this->metrics[1] = ['label' => 'Training current', 'value' => $configured ? $counts['Current'] : null, 'total' => $configured ? $total : 0, 'display' => $configured ? round($counts['Current'] / $total * 100).'%' : '—', 'instrumentCaption' => $configured ? $counts['Current'].' of '.$total.' staff' : 'Not assessed', 'caption' => $counts['Not configured'] ? $counts['Not configured'].' '.($counts['Not configured'] === 1 ? 'staff member has' : 'staff have').' no requirements set' : 'All applicable training requirements', 'href' => '/hr/compliance'];
        $this->addAttention('Staff training needs follow-up', $counts['Needs follow-up'], '/hr/compliance', 'warning');
        $this->blocks['hr-documents'] = $this->ready('Upcoming compliance requirement renewals', '/hr/compliance', collect($renewals)->sortBy('date')->take(6)->values()->all(), count($renewals));
        $this->mergePeopleDeadlines($renewals);
    }

    private function personal(): void
    {
        $assignments = HrCourseAssignment::query()->where('user_id', $this->user->id);
        $this->blocks['worker-learning'] = $this->ready('Your assigned courses · personal, across sites', '/hr/my/training', $this->groups($assignments, 'status'), (clone $assignments)->count());
        $today = CarbonImmutable::now($this->start->timezone)->startOfDay();
        $late = (clone $assignments)->whereNotIn('status', ['completed', 'waived'])->whereDate('due_at', '<', $today->toDateString())->count();
        $this->addAttention('Learning is overdue', $late, '/hr/my/training', 'warning');
        $learningDue = (clone $assignments)->whereNotIn('status', ['completed', 'waived'])->whereNotNull('due_at')
            ->whereDate('due_at', '<=', $today->addDays(14)->toDateString())->orderBy('due_at')->limit(6)->get(['id', 'due_at'])
            ->map(fn ($assignment) => ['label' => 'Learning assignment due', 'date' => $assignment->due_at->toDateString(), 'detail' => 'Open My learning for the course and next step', 'status' => $assignment->due_at->toDateString() < $today->toDateString() ? 'Overdue' : 'Learning', 'tone' => $assignment->due_at->toDateString() < $today->toDateString() ? 'warning' : 'neutral', 'href' => '/hr/my/training'])->all();
        if (($this->blocks['agenda']['state'] ?? null) === 'ready') {
            $this->blocks['agenda']['rows'] = collect([...$this->blocks['agenda']['rows'], ...$learningDue])->sortBy('date')->take(6)->values()->all();
            $this->blocks['agenda']['total'] = count($this->blocks['agenda']['rows']);
            $this->blocks['agenda']['subtitle'] = 'Your shifts and learning deadlines · includes overdue learning';
            $this->blocks['agenda']['footnote'] = 'Next 14 days · personal learning is across sites';
        }
        $this->blocks['worker-contacts'] = $this->ready('Your support workspaces', null, [
            ['label' => 'Team directory', 'detail' => 'Find current colleagues in your approved sites', 'href' => '/hr/my/directory'],
            ...($this->user->canDo('it.request') ? [['label' => 'IT service desk', 'detail' => 'Ask for device, login or connection help', 'href' => '/it']] : []),
        ]);
        if ($this->user->canDo('it.request') || $this->user->canDo('it.view')) {
            $requests = app(ItWorkAccessService::class)->applyViewScope(ItTicket::query(), $this->user)
                ->where('requester_user_id', $this->user->id)->whereIn('status', ItTicket::OPEN_STATUSES)->whereNull('merged_into_ticket_id');
            $this->blocks['worker-requests'] = $this->ready('Your open IT requests · personal, across sites', '/it', (clone $requests)->latest()->limit(6)->get(['id', 'reference', 'status', 'created_at'])->map(fn ($t) => ['label' => $t->reference ?: 'Support request', 'date' => $t->created_at->toIso8601String(), 'status' => Str::headline($t->status), 'detail' => 'Open your request for the latest response', 'href' => '/it/tickets/'.$t->id])->all(), $requests->count());
        }
        $attestations = HrPolicyAttestation::query()->where('user_id', $this->user->id)->orderByDesc('attested_at')->get()->unique('policy_id')->keyBy('policy_id');
        $policies = HrPolicy::active()->where('requires_attestation', true)->with(['versions' => fn ($q) => $q->where('is_current', true)])->orderBy('title')->get()->filter(function ($policy) use ($attestations) {
            $attestation = $attestations->get($policy->id);
            $version = $policy->versions->first();
            $current = $attestation && ($attestation->policy_version_id === null || ! $version || (int) $attestation->policy_version_id === (int) $version->id);
            if ($current && $policy->attestation_frequency_months && $attestation->attested_at) {
                $current = ! $attestation->attested_at->copy()->addMonths((int) $policy->attestation_frequency_months)->isPast();
            }

            return ! $current;
        });
        $this->blocks['worker-policy-reading'] = $this->ready('Your outstanding policy confirmations · personal', '/hr/my/policies', $policies->take(6)->map(fn ($p) => ['label' => $p->title, 'detail' => 'Read the current policy before confirming', 'status' => 'To read', 'href' => '/hr/my/policies'])->values()->all(), $policies->count());
    }

    private function it(): void
    {
        $query = app(ItWorkAccessService::class)->applyViewScope(ItTicket::query(), $this->user)->whereNull('merged_into_ticket_id');
        if ($this->site !== 'all') {
            $query->where('site_id', (int) $this->site);
        }
        $open = (clone $query)->whereIn('status', ItTicket::OPEN_STATUSES);
        $href = '/it?tab=tickets&view=all_open';
        $n = (clone $open)->count();
        $urgent = (clone $open)->where('priority', 'urgent')->count();
        $unassigned = (clone $open)->whereNull('assigned_to_user_id')->count();
        $this->addAttention('Urgent support work', $urgent, $href.'&ticket_priority=urgent', 'critical');
        $this->addAttention('Tickets needing an owner', $unassigned, '/it?tab=tickets&view=unassigned', 'warning');
        $this->metrics[] = ['label' => 'Open tickets', 'value' => $n, 'caption' => 'Open service work you can see', 'href' => $href];
        $this->metrics[] = ['label' => 'Devices reporting', 'value' => null, 'instrument' => 'bar', 'display' => '—', 'caption' => 'Device monitoring permission required'];
        $sla = app(ItSlaReadService::class);
        $due = $this->period(clone $query, 'first_response_due_at')->where('first_response_due_at', '<=', $sla->evaluatedAt());
        $outcomes = $sla->summarize($due)['clocks']['first_response']['by_state'];
        $met = $outcomes['met'] ?? 0;
        $measured = $met + ($outcomes['breached'] ?? 0);
        $this->metrics[] = ['label' => 'Response target', 'value' => $measured ? $met : null, 'total' => $measured,
            'display' => $measured ? round($met / $measured * 100).'%' : '—', 'instrumentCaption' => $measured ? $met.' of '.$measured.' on time' : 'No measured deadlines',
            'caption' => 'First responses due so far', 'href' => '/it'];
        $waiting = (clone $open)->whereNotNull('assigned_to_user_id')->where('status', 'waiting')->count();
        $this->blocks['distribution'] = $this->ready('Current open work · each ticket counted once', $href, [
            ['label' => 'In progress', 'value' => $n - $waiting - $unassigned], ['label' => 'Waiting', 'value' => $waiting], ['label' => 'Unassigned', 'value' => $unassigned],
        ], $n);
        $this->blocks['distribution'] += ['title' => 'Ticket workload', 'footnote' => 'Assigned open work includes work not yet started'];
        $opened = $this->days($this->period(clone $query, 'created_at'), 'created_at');
        $resolved = $this->days($this->period(clone $query, 'resolved_at'), 'resolved_at');
        $localToday = now($this->start->timezone)->toDateString();
        $series = array_map(fn ($point, $index) => ['label' => $point['label'], 'value' => $point['label'] > $localToday ? null : $point['value'], 'comparison' => $point['label'] > $localToday ? null : $resolved[$index]['value']], $opened, array_keys($opened));
        $this->blocks['trend'] = $this->ready('Opened and latest recorded resolutions · selected period', $href, [], array_sum(array_column($opened, 'value')), $series);
        $this->blocks['trend'] += ['title' => 'Support demand', 'seriesLabel' => 'Opened', 'comparisonLabel' => 'Resolved', 'footnote' => 'Future dates are unmeasured; reopened tickets use their latest resolution'];
        $next = (clone $open)->awaitingIt()->orderByRaw('first_responded_at IS NOT NULL OR first_response_due_at IS NULL')
            ->orderBy('first_response_due_at')->orderByRaw("CASE WHEN priority = 'urgent' THEN 0 WHEN priority = 'high' THEN 1 ELSE 2 END")->oldest()->first();
        // Older tickets may have no recorded conversation responsibility. Make
        // that a review action without pretending a response clock exists.
        $confirmedResponse = $next !== null;
        $next ??= (clone $open)->whereNull('next_response_party')->orderByRaw("CASE WHEN priority = 'urgent' THEN 0 WHEN priority = 'high' THEN 1 ELSE 2 END")->oldest()->first();
        $clock = $next ? $sla->present($next)['sla']['clocks']['first_response'] : null;
        $deadline = $confirmedResponse && ! $next->first_responded_at && ($clock['state'] ?? 'unmeasured') !== 'unmeasured' ? $clock['due_at'] : null;
        $rows = $next ? [['label' => $next->reference ?: 'Support request', 'date' => $deadline, 'status' => Str::headline($next->priority),
            'detail' => $deadline ? 'First response deadline · '.Str::headline($clock['state']) : ($confirmedResponse ? 'Awaiting IT · no measured next-response deadline' : 'Check who needs to respond next · responsibility not recorded'), 'href' => '/it/tickets/'.$next->id]] : [];
        $this->blocks['focus'] = $this->ready('Awaiting IT · soonest response deadline first', $href, $rows);
        $this->blocks['focus'] += ['title' => 'Your next response', 'actionLabel' => 'Review support request', 'emptyActionLabel' => 'Open service desk', 'focusTag' => $next ? Str::headline($next->priority) : 'Response queue', 'empty' => 'No support requests are waiting for an IT response.'];
        if (! $next) {
            $this->blocks['focus']['headline'] = 'No responses due';
        }
        if ($next && ! $deadline) {
            $this->blocks['focus']['headline'] = $confirmedResponse ? 'Response needed' : 'Review needed';
        }
        $changes = ItChange::query()->whereIn('ticket_id', (clone $open)->select('it_tickets.id'))->whereNull('implemented_at')->whereNotNull('maintenance_starts_at')
            ->where('maintenance_starts_at', '<', now()->addDays(30))->where(fn ($q) => $q->where('maintenance_starts_at', '>=', now())->orWhere('maintenance_ends_at', '>=', now()));
        $planned = (clone $changes)->with('ticket:id,reference')->orderBy('maintenance_starts_at')->limit(6)->get()->map(fn ($change) => [
            'label' => ($change->ticket->reference ?: 'IT change').' · planned change', 'date' => $change->maintenance_starts_at->toIso8601String(), 'end' => $change->maintenance_ends_at?->toIso8601String(),
            'status' => Str::headline($change->change_type), 'href' => '/it/changes/'.$change->id,
            // Restricted changes keep their risk out of the overview.
            'actionLabel' => 'Open change', 'facts' => array_values(array_filter([
                ['label' => 'Request', 'value' => $change->ticket->reference ?: 'Not recorded'],
                ['label' => 'Change type', 'value' => Str::headline($change->change_type)],
                ! $change->is_restricted && $change->risk_level ? ['label' => 'Risk', 'value' => Str::headline($change->risk_level)] : null,
                ['label' => 'Starts', 'dateTime' => $change->maintenance_starts_at->toIso8601String()],
                ['label' => 'Ends', 'dateTime' => $change->maintenance_ends_at?->toIso8601String(), 'value' => $change->maintenance_ends_at ? null : 'Not recorded'],
            ])),
        ])->all();
        $this->blocks['agenda'] = $this->ready('Scheduled changes · next 30 days', '/it/changes', $planned, (clone $changes)->count());
        $this->blocks['agenda']['title'] = 'Planned IT work';
        $this->blocks['it-changes'] = $this->blocks['agenda'];
        $this->blocks['it-age'] = $this->ready('Age of currently open tickets', $href, [
            ['label' => 'Under 2 days', 'value' => (clone $open)->where('created_at', '>=', now()->subDays(2))->count()],
            ['label' => '2–7 days', 'value' => (clone $open)->where('created_at', '<', now()->subDays(2))->where('created_at', '>=', now()->subDays(7))->count()],
            ['label' => 'Over 7 days', 'value' => (clone $open)->where('created_at', '<', now()->subDays(7))->count()],
        ], $n);
        $this->blocks['it-support-themes'] = $this->ready('Open tickets by recorded category', $href, $this->groups($open, 'category'), $n);
        $this->blocks['it-access'] = $this->ready('Open account requests', $href, [], (clone $open)->where('category', 'account')->count());
        $this->restricted('sites');
        $this->blocks['sites']['title'] = 'Service health';
    }

    private function itDevices(): void
    {
        $access = app(SecurityDevicesAccessService::class);
        if (! $this->user->canDo('securityDevices.viewAny') || ! $this->user->canDo('securityDevices.devices.view')) {
            foreach (['sites', 'it-assets', 'it-inventory'] as $id) {
                $this->restricted($id);
            }
            $this->blocks['sites']['title'] = 'Service health';

            return;
        }
        $devicesBySite = collect($this->sites)->whereIn('id', $this->siteIds)->mapWithKeys(fn ($site) => [$site['id'] => $access->visibleDevicesForSite($this->user, $site['id'])->whereNotIn('status', ['decommissioned', 'in_stock', 'lost', 'quarantined'])->get()]);
        $devices = $devicesBySite->flatten(1)->unique('id');
        $canMonitor = $this->user->canDo('securityDevices.events.view');
        $freshness = $canMonitor ? app(CommandObservationFreshnessService::class)->inspectMany($devices)->map(fn ($result) => $result->state) : collect();
        $history = app(OverviewConnectionHistory::class)->read($this->user, $devices, $this->siteIds);
        $reporting = $freshness->filter(fn ($state) => $state === 'fresh')->count();
        $total = $devices->count();
        $known = $canMonitor && $total > 0;
        $this->metrics[1] = ['label' => 'Devices reporting', 'value' => $known ? $reporting : null, 'instrument' => 'bar', 'display' => $known ? round($reporting / $total * 100).'%' : '—',
            'percent' => $known ? round($reporting / $total * 100) : null, 'caption' => $canMonitor ? $reporting.' of '.$total.' devices reporting recently' : 'Monitoring permission required', 'href' => '/security-devices'];
        $rows = collect($this->sites)->whereIn('id', $this->siteIds)->map(function ($site) use ($devicesBySite, $freshness, $canMonitor, $history) {
            $items = $devicesBySite[$site['id']];
            $fresh = $items->filter(fn ($device) => ($freshness[$device->id] ?? null) === 'fresh')->count();
            $critical = $items->contains(fn ($device) => $device->health_status?->value === 'critical');
            $attention = $critical || $items->contains(fn ($device) => in_array($device->health_status?->value, ['warning', 'unknown'], true) || $device->status?->value !== 'active');
            $unknown = ! $canMonitor || $items->isEmpty();

            return ['label' => $site['name'], 'value' => $items->count(), 'status' => $unknown ? 'Not assessed' : ($attention || $fresh < $items->count() ? 'Needs attention' : 'Reporting'),
                'tone' => $critical ? 'critical' : (! $unknown && ($attention || $fresh < $items->count()) ? 'warning' : 'neutral'),
                'detail' => $canMonitor ? $fresh.' reporting · '.$items->count().' deployed '.Str::plural('device', $items->count()) : 'Device monitoring permission required', 'history' => $history[$site['id']] ?? null, 'href' => '/security-devices/sites/'.$site['id'],
                'actionLabel' => 'Open site devices', 'facts' => $canMonitor ? [
                    ['label' => 'Deployed devices', 'value' => (string) $items->count()],
                    ['label' => 'Reporting recently', 'value' => (string) $fresh],
                    ['label' => 'Needing attention', 'value' => (string) $items->filter(fn ($device) => in_array($device->health_status?->value, ['critical', 'warning', 'unknown'], true) || $device->status?->value !== 'active')->count()],
                ] : null];
        })->values()->all();
        $this->blocks['sites'] = $this->ready('Current device observations · your sites', '/security-devices', $rows, $total);
        $this->blocks['sites'] += ['title' => 'Service health', 'presentation' => 'service-health', 'footnote' => 'Recorded connectivity checks · 24 hourly windows · current hour partial'];
        $this->blocks['it-assets'] = $this->ready('Deployed devices · site assignments', '/security-devices/devices', $devices->groupBy(fn ($device) => $device->status->value)->map(fn ($items, $status) => ['label' => Str::headline($status), 'value' => $items->count()])->values()->all(), $total);
        $this->blocks['it-inventory'] = $this->ready('Deployed devices by category', '/security-devices/devices', $devices->groupBy('category')->map(fn ($items, $category) => ['label' => Str::headline($category ?: 'Not recorded'), 'value' => $items->count()])->values()->all(), $total);
        if ($canMonitor) {
            $this->addAttention('Devices need a fresh observation', $total - $reporting, '/security-devices', 'warning');
        }
        if ($this->user->canDo('securityDevices.maintenance.view')) {
            $maintenanceQuery = DeviceMaintenanceRecord::query()->whereIn('device_id', $devices->pluck('id'))->upcoming(30);
            $maintenance = (clone $maintenanceQuery)->orderBy('scheduled_for')->limit(6)->get();
            $rows = $maintenance->map(fn ($record) => ['label' => 'Device maintenance · '.Str::headline($record->type), 'date' => $record->scheduled_for->toDateString(), 'status' => 'Scheduled', 'href' => '/security-devices/maintenance'])->all();
            $this->blocks['agenda']['rows'] = collect([...$this->blocks['agenda']['rows'], ...$rows])->sortBy('date')->take(6)->values()->all();
            $this->blocks['agenda']['total'] += $maintenanceQuery->count();
            $this->blocks['agenda']['subtitle'] = 'Scheduled changes and device maintenance · next 30 days';
        }
    }

    private function governance(): void
    {
        // These summaries exist; a missing module grant must not label them as future work.
        foreach (['governance-risk-milestones', 'governance-assurance', 'governance-reporting', 'governance-policy', 'governance-committees'] as $id) {
            $this->restricted($id);
        }
        $access = app(GovernanceRecordAccessService::class);
        $href = '/governance/my-work';
        if ($this->user->canDo('governance.resolutions.view') && $this->user->can('viewAny', Resolution::class)) {
            $decisions = $access->scopeResolutions(Resolution::query(), $this->user)->whereIn('status', ['proposed', 'open']);
            $this->blocks['attention']['decisions'] = (clone $decisions)->with('proposedBy:id,name')->orderByRaw('deadline IS NULL')->orderBy('deadline')->limit(3)->get()->map(fn ($decision) => [
                'label' => $decision->title, 'detail' => $decision->proposedBy?->name ?: 'Proposer not recorded', 'date' => $decision->deadline?->toIso8601String(),
                'status' => $decision->status === 'open' ? 'Open decision' : 'Proposed', 'href' => '/governance/resolutions/'.$decision->id,
            ])->all();
            $this->addAttention('Board decisions to review', (clone $decisions)->count(), '/governance/resolutions');
        }
        $actions = $access->scopeActionItems(ActionItem::query(), $this->user);
        $today = ComplianceObligation::nzToday();
        $periodActions = (clone $actions)->whereDate('due_date', '>=', $this->start->toDateString())->whereDate('due_date', '<', $this->end->toDateString());
        $count = (clone $periodActions)->count();
        $done = (clone $periodActions)->whereNotNull('completed_at')->count();
        $late = (clone $actions)->whereNull('completed_at')->whereDate('due_date', '<', $today)->count();
        $periodLate = (clone $periodActions)->whereNull('completed_at')->whereDate('due_date', '<', $today)->count();
        $this->addAttention('Overdue board actions', $late, $href, 'warning');
        $this->blocks['distribution'] = $this->ready('Board actions due in the selected period', $href, [['label' => 'Completed', 'value' => $done], ['label' => 'Open', 'value' => max(0, $count - $done - $periodLate)], ['label' => 'Overdue', 'value' => $periodLate]], $count);
        $this->blocks['distribution']['title'] = 'Board action status';
        $this->metrics = [
            ['label' => 'Open risks', 'value' => null, 'caption' => 'Risk register permission required'],
            ['label' => 'Obligations on time', 'value' => null, 'display' => '—', 'instrument' => 'bar', 'caption' => 'Compliance permission required'],
            ['label' => 'Board actions closed', 'value' => $done, 'total' => $count, 'caption' => 'Actions due · selected period', 'href' => $href],
        ];
        $calendar = (clone $actions)->whereNull('completed_at')->whereNotNull('due_date')->orderBy('due_date')->limit(6)->get(['id', 'title', 'due_date', 'status'])->map(fn ($a) => ['label' => $a->title, 'date' => $a->due_date->toDateString(), 'status' => 'Board action', 'href' => $href])->all();
        $this->blocks['trend'] = $this->ready('Current ratings, evidence gaps and accountable owners', null);
        $this->blocks['trend'] += ['title' => 'Risk and assurance', 'presentation' => 'assurance', 'sections' => []];
        $riskSection = ['title' => 'Risks to watch', 'caption' => 'Current residual rating', 'state' => 'restricted', 'rows' => []];
        if ($this->user->canDo('governance.risks.view')) {
            $risks = RiskRegisterEntry::query()->current();
            $this->metrics[0] = ['label' => 'Open risks', 'value' => RiskRegisterEntry::query()->openStatus()->count(), 'caption' => 'Current open risk register', 'href' => '/governance/risks'];
            $due = (clone $risks)->whereDate('next_review_date', '<=', $today)->count();
            $this->addAttention('Risk reviews due', $due, '/governance/risks', 'warning');
            $riskRows = (clone $risks)->with('riskOwner:id,name')->orderByDesc('residual_score')->orderBy('next_review_date')->limit(4)->get()->map(fn ($risk) => [
                'label' => $risk->title, 'detail' => $risk->riskOwner?->name ?: 'Owner not assigned', 'date' => $risk->next_review_date?->toDateString(),
                'status' => $risk->isCritical() ? 'Critical' : ($risk->isHigh() ? 'High' : ($risk->isMedium() ? 'Medium' : 'Low')),
                'tone' => $risk->isCritical() ? 'critical' : ($risk->isHigh() ? 'warning' : 'neutral'), 'href' => '/governance/risks/'.$risk->id,
                'actionLabel' => 'Open risk', 'facts' => array_values(array_filter([
                    $risk->risk_reference ? ['label' => 'Reference', 'value' => $risk->risk_reference] : null,
                    $risk->category ? ['label' => 'Category', 'value' => Str::headline($risk->category)] : null,
                    ['label' => 'Owner', 'value' => $risk->riskOwner?->name ?: 'Not assigned'],
                    $risk->residual_score !== null ? ['label' => 'Residual score', 'value' => $risk->residual_score.($risk->within_appetite === null ? '' : ($risk->within_appetite ? ' · within appetite' : ' · above appetite'))] : null,
                    $risk->risk_committee ? ['label' => 'Committee', 'value' => Str::headline($risk->risk_committee)] : null,
                    ['label' => 'Next review', 'date' => $risk->next_review_date?->toDateString(), 'value' => $risk->next_review_date ? null : 'Not scheduled'],
                ])),
            ])->all();
            $riskSection = ['title' => 'Risks to watch', 'caption' => 'Current residual rating', 'state' => 'ready', 'rows' => $riskRows];
            $reviewRows = (clone $risks)->whereNotNull('next_review_date')->orderBy('next_review_date')->limit(6)->get()->map(fn ($risk) => ['label' => $risk->title, 'date' => $risk->next_review_date->toDateString(), 'status' => 'Risk review', 'href' => '/governance/risks/'.$risk->id])->all();
            $this->blocks['governance-risk-milestones'] = $this->ready('Next register reviews · organisation-wide', '/governance/risks', $reviewRows);
        }
        $evidenceSection = ['title' => 'Assurance evidence', 'caption' => 'Organisation-wide', 'state' => 'restricted', 'rows' => []];
        if ($this->user->canDo('governance.compliance.view')) {
            $obligations = ComplianceObligation::query()->counted()->whereDate('due_date', '>=', $this->start->toDateString())->whereDate('due_date', '<', $this->end->toDateString());
            $total = (clone $obligations)->count();
            $onTime = (clone $obligations)->onTime($today)->count();
            $this->metrics[1] = ['label' => 'Obligations on time', 'value' => $total ? $onTime : null, 'display' => $total ? round($onTime / $total * 100).'%' : '—', 'instrument' => 'bar', 'percent' => $total ? round($onTime / $total * 100) : null,
                'caption' => $onTime.' of '.$total.' on time this period', 'href' => '/governance/compliance'];
            $this->addAttention('Compliance obligations overdue', ComplianceObligation::query()->overdue($today)->count(), '/governance/compliance', 'warning');
            $exceptions = ComplianceObligation::query()->counted()->whereDate('due_date', '<=', ComplianceObligation::dueSoonUntil($today))
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('evidence_required', true)->where('evidence_provided', false))
                    ->orWhere(fn ($q) => $q->where('sign_off_required', true)->whereNull('signed_off_at')));
            $evidenceRows = (clone $exceptions)->with('owner:id,name')->orderBy('due_date')->limit(4)->get()->map(fn ($item) => [
                'label' => $item->obligation_title, 'detail' => $item->owner?->name ?: 'Owner not assigned', 'date' => $item->due_date->toDateString(),
                'status' => $item->evidence_required && ! $item->evidence_provided ? 'Awaiting evidence' : 'Awaiting sign-off',
                'tone' => $item->due_date->toDateString() < $today ? 'critical' : 'warning', 'href' => '/governance/compliance/'.$item->id,
                'actionLabel' => 'Open obligation', 'facts' => [
                    ['label' => 'Owner', 'value' => $item->owner?->name ?: 'Not assigned'],
                    ['label' => 'Due', 'date' => $item->due_date->toDateString()],
                    ['label' => 'Evidence', 'value' => ! $item->evidence_required ? 'Not required' : ($item->evidence_provided ? 'Provided' : 'Still needed')],
                    ['label' => 'Sign-off', 'value' => ! $item->sign_off_required ? 'Not required' : ($item->signed_off_at ? 'Signed off' : 'Still needed')],
                ],
            ])->all();
            $evidenceSection = ['title' => 'Assurance evidence', 'caption' => 'Due within 30 days, including overdue', 'state' => 'ready', 'rows' => $evidenceRows];
            $this->blocks['governance-assurance'] = $this->ready('Required evidence and sign-off still outstanding', '/governance/compliance', $evidenceRows, (clone $exceptions)->count());
            $obligationDates = ComplianceObligation::query()->open()->whereNotNull('due_date')->orderBy('due_date')->limit(6)->get()->map(fn ($item) => ['label' => $item->obligation_title, 'date' => $item->due_date->toDateString(), 'status' => 'Obligation', 'href' => '/governance/compliance/'.$item->id])->all();
            $calendar = [...$calendar, ...$obligationDates];
            $this->blocks['governance-reporting'] = $this->ready('Open obligation deadlines', '/governance/compliance/calendar', $obligationDates);
        } else {
            $this->restricted('governance-assurance');
            $this->restricted('governance-reporting');
        }
        $this->blocks['trend']['sections'] = [$riskSection, $evidenceSection];
        $this->blocks['trend']['total'] = count($riskSection['rows']) + count($evidenceSection['rows']);
        $this->restricted('sites');
        $this->blocks['sites']['title'] = 'Strategic priorities';
        if ($this->user->canDo('governance.strategy.view')) {
            $goals = StrategicGoal::query()->whereHas('strategicPlan', fn ($q) => $q->active()->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today));
            $rows = (clone $goals)->with('leadExecutive:id,name')->orderBy('order')->limit(6)->get()->map(fn ($goal) => [
                'label' => $goal->title, 'value' => $goal->progress_pct === null ? null : (float) $goal->progress_pct, 'target' => 100,
                'status' => Str::headline($goal->status), 'tone' => $goal->status === 'at_risk' ? 'warning' : 'neutral',
                'detail' => $goal->leadExecutive?->name ?: 'Owner not assigned', 'href' => '/governance/strategy/'.$goal->strategic_plan_id,
                'actionLabel' => 'Open strategic plan', 'facts' => array_values(array_filter([
                    ['label' => 'Lead', 'value' => $goal->leadExecutive?->name ?: 'Not assigned'],
                    $goal->pillar ? ['label' => 'Pillar', 'value' => Str::headline($goal->pillar)] : null,
                    $goal->timeframe ? ['label' => 'Timeframe', 'value' => Str::headline($goal->timeframe)] : null,
                    ['label' => 'Recorded progress', 'value' => $goal->progress_pct === null ? 'Not recorded' : round((float) $goal->progress_pct).'%'],
                ])),
            ])->all();
            $this->blocks['sites'] = $this->ready('Current approved plans · recorded progress', '/governance/strategy', $rows, (clone $goals)->count());
            $this->blocks['sites'] += ['title' => 'Strategic priorities', 'presentation' => 'priorities'];
        }
        if ($this->user->canDo('governance.policies.view')) {
            $query = GovernancePolicy::query();
            $this->blocks['governance-policy'] = $this->ready('Policy register · organisation-wide', '/governance/policies', $this->groups($query, 'status'), (clone $query)->count());
        }
        $this->restricted('focus');
        $this->blocks['focus']['title'] = 'Next board meeting';
        if ($this->user->canDo('governance.meetings.view')) {
            $meetingQuery = $access->scopeMeetings(GovernanceMeeting::query(), $this->user)->whereNotIn('status', ['cancelled', 'completed'])->where('scheduled_at', '>=', now());
            $meetings = (clone $meetingQuery)->orderBy('scheduled_at')->limit(6)->get();
            $this->blocks['governance-committees'] = $this->ready('Upcoming meetings · organisation-wide', '/governance/meetings', $meetings->map(fn ($m) => ['label' => $m->title, 'date' => $m->scheduled_at?->toIso8601String(), 'detail' => 'Open the meeting for papers and reports', 'href' => '/governance/meetings/'.$m->id])->all());
            $this->blocks['governance-committees']['title'] = 'Upcoming committee meetings';
            $next = (clone $meetingQuery)->where('meeting_type', 'board')->orderBy('scheduled_at')->first();
            $packs = $next ? $access->scopeBoardPacks(BoardPack::query(), $this->user)->where('governance_meeting_id', $next->id)->where('is_current', true)->count() : 0;
            $this->blocks['focus'] = $this->ready('Next board meeting', '/governance/meetings', $next ? [[
                'label' => $next->title, 'date' => $next->scheduled_at->toIso8601String(), 'end' => $next->duration_minutes ? $next->scheduled_at->copy()->addMinutes($next->duration_minutes)->toIso8601String() : null,
                'detail' => $packs ? $packs.' board pack available in your view' : 'No board papers available in your view', 'href' => '/governance/meetings/'.$next->id,
            ]] : []);
            $this->blocks['focus'] += ['title' => 'Next board meeting', 'focusTag' => 'Board preparation', 'actionLabel' => 'Open meeting', 'emptyActionLabel' => 'Open meetings', 'empty' => 'No board meeting is scheduled in your view yet.'];
            if (! $next) {
                $this->blocks['focus']['headline'] = 'No meeting scheduled';
            }
            if ($next) {
                $localMeeting = $next->scheduled_at->copy()->setTimezone($this->start->timezone);
                $this->blocks['focus']['headline'] = $localMeeting->format('j F');
                $this->blocks['focus']['focusMeta'] = [
                    $localMeeting->format('l · g:i a').($next->duration_minutes ? '–'.$localMeeting->copy()->addMinutes($next->duration_minutes)->format('g:i a') : ''),
                    $next->preread_deadline ? 'Papers close '.$next->preread_deadline->copy()->setTimezone($this->start->timezone)->format('j M') : 'Paper deadline not recorded',
                ];
            }
            $calendar = [...$calendar, ...$meetings->map(fn ($meeting) => ['label' => $meeting->title, 'date' => $meeting->scheduled_at->toIso8601String(), 'status' => 'Meeting', 'href' => '/governance/meetings/'.$meeting->id])->all()];
        }
        $this->blocks['agenda'] = $this->ready('Next meetings, actions and obligations', '/governance/my-work', collect($calendar)->sortBy('date')->take(6)->values()->all());
        $this->blocks['agenda']['title'] = 'Governance calendar';
    }
}
