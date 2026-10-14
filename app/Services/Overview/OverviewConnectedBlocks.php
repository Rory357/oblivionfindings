<?php

namespace App\Services\Overview;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Domain\Hr\Models\HrCompetencyAssessment;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Services\EngagementService;
use App\Domain\Hr\Services\HrAssetAccessService;
use App\Domain\Hr\Services\HrPayrollAccessService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Domain\Hr\Services\HrWellbeingAccessService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Roadmap\Models\DecisionRequest;
use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Models\InitiativeBenefit;
use App\Domain\Roadmap\Models\InitiativeDependency;
use App\Domain\Roadmap\Models\InitiativeTask;
use App\Models\CarePlan;
use App\Models\ClientNote;
use App\Models\FleetOuting;
use App\Models\FleetResidentTransport;
use App\Models\ItTicket;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\Site;
use App\Models\SiteChecklistRun;
use App\Models\SiteFeedback;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Services\Fleet\ResidentTransportJourneyScope;
use App\Services\ShiftStaffEligibilityService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Small permission-preserving projections. Never return private record bodies. */
final class OverviewConnectedBlocks
{
    public const BLOCKS = [
        'worker-plan-updates', 'worker-activities', 'worker-shift-offers', 'worker-equipment',
        'lead-equipment', 'lead-notes', 'lead-capabilities', 'lead-transport',
        'manager-improvements', 'manager-feedback', 'manager-quality', 'manager-owners',
        'manager-goal-reviews', 'manager-activities', 'manager-benefits',
        'hr-survey', 'hr-payroll-checks', 'hr-development',
        'governance-audit', 'governance-delegations', 'governance-dependencies',
    ];

    private array $activity = [];

    public function __construct(private readonly UserSiteAccessService $sites) {}

    public function read(string $id, User $user, array $sites, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $result = match ($id) {
            'worker-plan-updates', 'manager-goal-reviews' => $this->carePlans($user, $sites, $id === 'worker-plan-updates'),
            'worker-activities', 'manager-activities' => $this->activities($user, $sites, $start, $end, $id === 'worker-activities'),
            'worker-shift-offers' => $this->offers($user, $sites),
            'worker-equipment' => $this->equipment($user),
            'lead-equipment' => $this->checklists($user, $sites),
            'lead-notes' => $this->notes($user, $sites, $start, $end),
            'lead-capabilities' => $this->capabilities($user, $sites),
            'lead-transport' => $this->transport($user, $sites),
            'manager-feedback' => $this->feedback($user, $sites),
            'manager-quality' => $this->quality($user, $sites),
            'hr-survey' => $this->surveys($user, $sites),
            'hr-payroll-checks' => $this->payroll($user, $sites),
            'hr-development' => $this->development($user, $sites),
            'governance-audit' => $this->audit($user),
            'governance-delegations' => $this->delegations($user),
            default => $this->roadmap($user, $sites, $id),
        };
        if (in_array($id, ['lead-equipment', 'lead-capabilities', 'manager-goal-reviews', 'hr-survey'], true)) {
            $result['presentation'] = 'progress-rows';
        }

        return $result;
    }

    private function restricted(): array
    {
        return ['state' => 'restricted', 'subtitle' => 'Permission required', 'message' => 'Your permissions do not include this source workspace.', 'rows' => [], 'series' => [], 'total' => null];
    }

    private function ready(string $subtitle, string $href, array $rows, int $total, string $footnote = ''): array
    {
        return ['state' => 'ready', 'subtitle' => $subtitle, 'href' => $href, 'rows' => $rows, 'series' => [], 'total' => $total, 'footnote' => $footnote];
    }

    /**
     * The worker's local midnight. Datetime columns are stored in UTC, so
     * comparisons against them pass ->utc(); date-only columns use the date.
     */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
    }

    private function siteIds(User $user, array $ids): array
    {
        return Site::query()->whereIn('id', $ids)->get()->filter(fn ($site) => Gate::forUser($user)->allows('view', $site))->pluck('id')->all();
    }

    private function selectedStaff(Builder $profile, array $sites): void
    {
        $profile->where(function ($q) use ($sites) {
            $q->whereIn('primary_site_id', $sites);
            foreach ($sites as $id) {
                $q->orWhereJsonContains('secondary_site_ids', $id);
            }
        });
    }

    private function groups(Builder $query, string $field = 'status'): array
    {
        return (clone $query)->select($field)->selectRaw('COUNT(*) as aggregate')->groupBy($field)->orderByDesc('aggregate')->get()->map(fn ($group) => [
            'label' => Str::headline($group->{$field} ?: 'Not recorded'), 'value' => (int) $group->aggregate,
        ])->all();
    }

    private function changes(Builder $query, string $label, string $href): void
    {
        foreach ((clone $query)->where('updated_at', '>=', $this->today()->subDays(6)->utc())->orderByDesc('updated_at')->limit(8)->get(['id', 'updated_at']) as $record) {
            $this->activity[$query->getModel()->getTable().':'.$record->id] = [
                'label' => $label.' #'.$record->id, 'detail' => 'Record updated', 'date' => $record->updated_at->toIso8601String(), 'href' => $href,
            ];
        }
    }

    public function updates(User $user, string $role, array $siteIds): array
    {
        if (in_array($role, ['worker', 'lead', 'manager'], true)
            && ($user->canDo('shifts.viewAny') || $user->canDo('shifts.viewAssigned'))) {
            $shifts = $this->sites->applyShiftScope(Shift::query(), $user, [])->whereIn('site_id', $siteIds);
            if ($role === 'worker' || ! $user->canDo('shifts.viewAny')) {
                $shifts->where('user_id', $user->id);
            }
            $this->changes($shifts, 'Shift', $role === 'worker' ? '/my-day' : '/operations/shifts');
        }
        if ($role === 'it' && ($user->canDo('it.view') || $user->canDo('it.manage'))) {
            $tickets = app(ItWorkAccessService::class)->applyViewScope(ItTicket::query(), $user)
                ->whereIn('site_id', $siteIds)->whereNull('merged_into_ticket_id');
            $this->changes($tickets, 'Service request', '/it');
        }
        $plans = app(OverviewPlans::class);
        foreach (OverviewPlanDefinitions::all() as $kind => $definition) {
            if ($definition['role'] !== $role || ! $plans->canRead($user, $definition)) {
                continue;
            }
            $query = $plans->query($user, $kind)->when($definition['scope'] === 'site', fn ($q) => $q->whereIn('site_id', $siteIds));
            $this->changes($query, $plans->definition($kind)['title'], '/work-plans/'.$kind);
        }
        $rows = collect($this->activity)->sortByDesc('date')->take(8)->values()->all();

        $result = $this->ready('Recently changed records · last 7 days', '/dashboard?role='.$role, $rows, count($rows), 'Latest changes from the connected summaries in this role; not a complete audit log')
            + ['scopeMode' => $role === 'governance' ? 'organisation' : 'site', 'empty' => 'No recent changes in the connected records you can access.'];
        unset($result['href']);

        return $result;
    }

    private function carePlans(User $user, array $sites, bool $personal): array
    {
        if (! $user->canDo('care_plans.viewAny')) {
            return $this->restricted();
        }
        $query = CarePlan::query()->active()->whereHas('client', function ($q) use ($user, $sites, $personal) {
            $this->sites->applyClientScope($q, $user, ['clients.viewAny'])->whereIn('site_id', $sites);
            if ($personal) {
                $q->whereHas('supportWorkers', fn ($staff) => $staff->where('users.id', $user->id));
            }
        });
        $href = '/operations/care-plans';
        $this->changes($query, 'Care plan', $href);
        if ($personal) {
            $query->where('updated_at', '>=', $this->today()->subDays(30)->utc());
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('updated_at')->limit(6)->get(['id', 'version', 'updated_at'])->map(fn ($p) => [
                'label' => 'Care plan #'.$p->id, 'status' => 'Version '.($p->version ?: 1), 'date' => $p->updated_at->toIso8601String(),
                'detail' => 'Updated · open the care plan to read', 'href' => $href.'/'.$p->id,
            ])->all();

            return $this->ready('Assigned clients · active plans updated in the last 30 days', $href, $rows, $total, 'Recent updates, not an unread count · private plan details stay in the care record');
        }
        $query->whereHas('goals');
        $total = (clone $query)->count();
        $missing = (clone $query)->whereNull('next_review_at')->count();
        $reviewed = (clone $query)->whereNotNull('reviewed_at')->count();
        $upcoming = (clone $query)->whereDate('next_review_at', '<=', $this->today()->addDays(30)->toDateString())->count();

        return $this->ready('Active care plans with personal goals · recorded review coverage', $href, [
            ['label' => 'Review recorded', 'value' => $reviewed, 'target' => $total],
            ['label' => 'Overdue or due within 30 days', 'value' => $upcoming, 'target' => $total],
            ['label' => 'No next review date', 'value' => $missing, 'target' => $total],
        ], $total, 'Plan-level review dates; goal details remain private. Categories can overlap.');
    }

    private function activities(User $user, array $sites, CarbonImmutable $start, CarbonImmutable $end, bool $personal): array
    {
        if (! $user->canDo('fleet.viewAny') && ! $user->canDo('assets.viewAny')) {
            return $this->restricted();
        }
        $scope = app(ResidentTransportJourneyScope::class);
        $query = $scope->applyOutingScope(FleetOuting::query(), $user)
            ->whereHas('clients', function ($q) use ($scope, $user, $sites, $personal) {
                $scope->applyClientScope($q, $user)->whereIn('site_id', $sites);
                if ($personal) {
                    $q->whereHas('supportWorkers', fn ($staff) => $staff->where('users.id', $user->id));
                }
            })->where('planned_departure', '>=', $start->utc())->where('planned_departure', '<', $end->utc());
        $href = '/fleet-assets/outings';
        $this->changes($query, 'Community outing', $href);
        $total = (clone $query)->count();
        $rows = $personal ? $query->orderBy('planned_departure')->limit(6)->get(['id', 'planned_departure', 'status'])->map(fn ($outing) => [
            'label' => 'Community outing #'.$outing->id, 'date' => $outing->planned_departure?->toIso8601String(), 'status' => Str::headline($outing->status), 'href' => $href.'/'.$outing->id,
        ])->all() : array_map(fn ($row) => $row + ['target' => $total, 'href' => $href], $this->groups($query));

        return $this->ready(($personal ? 'Assigned clients' : 'Your sites').' · outings in the selected period', $href, $rows, $total, 'Recorded community outings; other activities remain in their care records');
    }

    private function offers(User $user, array $sites): array
    {
        if (! collect(['job_board.viewAny', 'job_board.claim', 'shifts.viewAny', 'shifts.viewAssigned'])->contains(fn ($p) => $user->canDo($p))) {
            return $this->restricted();
        }
        $query = $this->sites->applyShiftOpenPositionScope(ShiftOpenPosition::query(), $user, ['reports.viewAny'])
            ->where('status', 'open')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('shift', fn ($q) => $q->whereIn('site_id', $sites)->where('starts_at', '>', now())->whereNotIn('status', ['completed', 'cancelled']));
        $href = '/operations/job-board';
        $rows = [];
        // Explicit bounded eligibility preview. Full matching and fresh claim checks remain on the job board.
        $positions = $query->with('shift')->orderBy('id')->limit(40)->get();
        $checked = $positions->count();
        // One batched check (shared relation loads and one shift query for the
        // user) instead of a full evaluation per offer.
        $eligibility = app(ShiftStaffEligibilityService::class)->evaluateMany($positions->pluck('shift')->unique('id')->values(), [$user]);
        foreach ($positions as $position) {
            if ($eligibility[$position->shift_id][$user->id]?->is_allowed ?? false) {
                $rows[] = ['label' => 'Open shift #'.$position->shift_id, 'date' => $position->shift->starts_at->toIso8601String(), 'status' => 'Eligible at last check', 'href' => $href];
            }
        }
        usort($rows, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $this->ready('Eligible offers preview · upcoming shifts at selected sites', $href, array_slice($rows, 0, 6), count($rows), 'Checked '.$checked.' open offers (up to 40). The job board rechecks eligibility before a claim.');
    }

    private function equipment(User $user): array
    {
        if (! $user->canDo('hr.assets.view')) {
            return $this->restricted();
        }
        $query = app(HrAssetAccessService::class)->visibleAssignments($user)->whereNull('returned_at')
            ->whereHas('employeeProfile', fn ($q) => $q->where('user_id', $user->id));
        $href = '/hr/assets?tab=assignments';
        $this->changes($query, 'Equipment assignment', $href);
        $total = (clone $query)->count();
        $rows = $query->with('asset:id,name,asset_tag')->orderByRaw('due_at IS NULL')->orderBy('due_at')->limit(6)->get()->map(fn ($a) => [
            'label' => $a->asset?->name ?? 'Equipment assignment #'.$a->id, 'date' => $a->due_at?->toIso8601String(),
            'status' => $a->due_at && $a->due_at->isPast() ? 'Return due' : 'On loan', 'detail' => $a->due_at ? 'Return date' : 'No return date recorded', 'href' => $href,
        ])->all();

        return $this->ready('Your current equipment assignments', $href, $rows, $total) + ['scopeMode' => 'personal'];
    }

    private function checklists(User $user, array $sites): array
    {
        if (! $user->canDo('checklists.view')) {
            return $this->restricted();
        }
        $query = SiteChecklistRun::query()->whereIn('site_id', $this->siteIds($user, $sites))
            ->whereHas('assignment', fn ($q) => $q->whereColumn('site_checklist_assignments.site_id', 'site_checklist_runs.site_id')->whereColumn('site_checklist_assignments.template_id', 'site_checklist_runs.template_id'))
            ->whereIn('status', ['scheduled', 'in_progress']);
        $total = (clone $query)->count();
        $this->changes($query, 'Site checklist', '/sites');
        $rows = $query->orderBy('scheduled_date')->limit(6)->get(['id', 'site_id', 'scheduled_date', 'completion_percentage', 'status'])->map(fn ($r) => [
            'label' => 'Checklist #'.$r->id, 'date' => $r->scheduled_date?->toDateString(), 'status' => Str::headline($r->status),
            'value' => (int) $r->completion_percentage, 'target' => 100, 'href' => '/checklists/runs/'.$r->id,
        ])->all();

        return $this->ready('Scheduled and in-progress checks · selected sites', '/sites', $rows, $total, 'Checklist completion is recorded in the site workspace');
    }

    private function notes(User $user, array $sites, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! $user->canDo('progress_notes.viewAny') || ! $user->canDo('shifts.viewAny')) {
            return $this->restricted();
        }
        $shifts = $this->sites->applyShiftScope(Shift::query(), $user, [])->whereIn('site_id', $sites)
            ->whereNotNull('client_id')->where('status', 'completed')->where('starts_at', '>=', $start->utc())->where('starts_at', '<', $end->utc());
        $ids = $shifts->pluck('id');
        $notes = ClientNote::query()->dailyNotes()->forUser($user)->whereIn('shift_id', $ids)->where('is_draft', false)
            ->whereHas('shift', fn ($q) => $q->whereColumn('shifts.client_id', 'client_notes.client_id')->whereColumn('shifts.user_id', 'client_notes.user_id'));
        $total = $ids->count();
        $with = (clone $notes)->distinct()->count('shift_id');
        $this->changes($notes, 'Submitted daily note', '/operations/shift-notes');

        return $this->ready('Completed client shifts · selected period', '/operations/shift-notes', [
            ['label' => 'Submitted daily note linked', 'value' => $with, 'target' => $total],
            ['label' => 'No submitted daily note linked', 'value' => $total - $with, 'target' => $total],
        ], $total, 'A missing linked note is a review prompt, not a finding that a required note is overdue.');
    }

    private function capabilities(User $user, array $sites): array
    {
        if (! $user->canDo('hr.performance.view')) {
            return $this->restricted();
        }
        $query = app(HrPerformanceAccessService::class)->applyCompetencyAssessmentScope(HrCompetencyAssessment::query(), $user)
            ->whereHas('employeeProfile', fn ($q) => $this->selectedStaff($q, $sites))
            ->where(fn ($q) => $q->whereNull('assessor_declared_at')->orWhereNull('staff_acknowledged_at'));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('assessment_date')->limit(6)->get(['id', 'assessment_date', 'assessor_declared_at', 'staff_acknowledged_at'])->map(fn ($a) => [
            'label' => 'Capability assessment #'.$a->id, 'date' => $a->assessment_date?->toDateString(),
            'value' => (int) filled($a->assessor_declared_at) + (int) filled($a->staff_acknowledged_at), 'target' => 2,
            'status' => $a->assessor_declared_at ? 'Staff acknowledgement needed' : 'Assessor sign-off needed', 'href' => '/hr/performance/competencies',
        ])->all();

        return $this->ready('Recorded assessments awaiting sign-off', '/hr/performance/competencies', $rows, $total, 'Assessment notes and scores remain in HR');
    }

    private function transport(User $user, array $sites): array
    {
        if (! $user->canDo('fleet.viewAny') && ! $user->canDo('assets.viewAny')) {
            return $this->restricted();
        }
        $query = app(ResidentTransportJourneyScope::class)->applyTransportScope(FleetResidentTransport::query(), $user)->whereIn('site_id', $sites)
            ->whereNotIn('status', ['completed', 'cancelled']);
        $href = '/fleet-assets/transports/planner';
        $this->changes($query, 'Transport journey', $href);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('updated_at')->limit(6)->get(['id', 'status', 'updated_at', 'review_required'])->map(fn ($j) => [
            'label' => 'Transport journey #'.$j->id, 'status' => $j->review_required ? 'Review required' : Str::headline($j->status),
            'date' => $j->updated_at->toIso8601String(), 'detail' => 'Last updated', 'href' => '/fleet-assets/transports/'.$j->id,
        ])->all();

        return $this->ready('Open recorded transport journeys · selected sites', $href, $rows, $total, 'Open the transport planner for booking and preparation details');
    }

    private function feedback(User $user, array $sites): array
    {
        if (! $user->canDo('sites.viewAny')) {
            return $this->restricted();
        }
        $query = SiteFeedback::query()->whereIn('site_id', $this->siteIds($user, $sites))->whereIn('status', ['new', 'acknowledged', 'in_progress']);
        $this->changes($query, 'Site feedback', '/sites');

        return $this->ready('Open feedback · selected sites', '/sites', $this->groups($query), $query->count(), 'Counts only · feedback text and respondent details stay in the site workspace');
    }

    private function quality(User $user, array $sites): array
    {
        if (! $user->canDo('checklists.view') || ! $user->canDo('sites.viewAny')) {
            return $this->restricted();
        }
        $query = SiteInspectionSchedule::query()->active()->whereIn('site_id', $this->siteIds($user, $sites));
        $this->changes($query, 'Inspection schedule', '/sites/inspections');
        $total = (clone $query)->count();
        $today = $this->today()->toDateString();
        $due = (clone $query)->whereDate('next_due_date', '<=', $today)->count();
        $missing = (clone $query)->whereNull('next_due_date')->count();

        return $this->ready('Active site inspection schedules', count($sites) === 1 ? '/sites/'.$sites[0].'/inspections' : '/sites/inspections', [
            ['label' => 'Due or overdue', 'value' => $due], ['label' => 'Future review scheduled', 'value' => $total - $due - $missing], ['label' => 'No next date recorded', 'value' => $missing],
        ], $total, 'Recorded inspection schedule coverage; not a quality score');
    }

    private function surveys(User $user, array $sites): array
    {
        if (! $user->canDo('hr.wellbeing.view') || ! $user->canDo('hr.performance.manage')) {
            return $this->restricted();
        }
        $access = app(HrWellbeingAccessService::class);
        $surveys = HrEngagementSurvey::query()->whereIn('status', ['published', 'closed'])->orderByDesc('published_at')->get()
            ->filter(fn ($survey) => $access->canManageSurvey($user, $survey))
            ->filter(fn ($survey) => $survey->audience_type === 'site'
                ? count($survey->audience_site_ids ?? []) > 0 && array_diff($survey->audience_site_ids, $sites) === []
                : array_diff(Site::query()->active()->notArchived()->whereNull('archived_at')->pluck('id')->all(), $sites) === []);
        $href = '/hr/wellbeing';
        $rows = $surveys->take(6)->map(fn ($survey) => [
            'label' => $survey->title, 'date' => $survey->ends_at?->toDateString(), 'status' => Str::headline($survey->status),
            'value' => $survey->responses()->count(), 'target' => app(EngagementService::class)->recipientCount($survey),
            'detail' => 'Responses / current eligible recipients', 'href' => $href.'/surveys/'.$survey->id,
        ])->values()->all();

        return $this->ready('Published and closed surveys you can see in full', $href, $rows, $surveys->count(), 'Participation counts only; recipient counts reflect current staff, so historical rates may differ');
    }

    private function payroll(User $user, array $sites): array
    {
        if (! $user->canDo('hr.payroll.view')) {
            return $this->restricted();
        }
        $query = app(HrPayrollAccessService::class)->visibleRunsQuery($user)->whereNull('exported_at')
            // Selected-site summaries cannot partition a payroll run. Legacy provenance stays in Payroll.
            ->where('source_provenance_status', 'verified')->whereDoesntHave('sourceUses', fn ($q) => $q->whereNotIn('site_id', $sites));
        $href = '/hr/payroll';
        $this->changes($query, 'Payroll preparation', $href);
        $total = (clone $query)->count();
        $rows = $query->orderBy('period_end')->limit(6)->get(['id', 'period_end', 'status', 'validation_errors'])->map(fn ($r) => [
            'label' => 'Payroll run #'.$r->id, 'date' => $r->period_end?->toDateString(), 'status' => Str::headline($r->status),
            'detail' => count($r->validation_errors ?? []).' recorded validation issues', 'href' => $href,
        ])->all();

        return $this->ready('Unexported payroll runs with verified site provenance', $href, $rows, $total, 'No pay amounts shown · full validation and older runs remain in Payroll');
    }

    private function development(User $user, array $sites): array
    {
        if (! $user->canDo('hr.performance.view')) {
            return $this->restricted();
        }
        $query = app(HrPerformanceAccessService::class)->applyCurrentSubjectScope(HrDevelopmentGoal::query(), $user)->open()
            ->whereHas('employee.hrEmployeeProfile', fn ($q) => $this->selectedStaff($q, $sites));
        $href = '/hr/goals?tab=development';
        $this->changes($query, 'Development plan', $href);

        return $this->ready('Open development plans · current staff', $href, $this->groups($query), $query->count(), 'Recorded development work; personal objectives and review notes remain in HR');
    }

    private function initiatives(User $user, array $sites, bool $organisation): Builder
    {
        $query = Initiative::query();
        if (! $organisation) {
            // A whole-initiative summary may only use initiatives wholly inside the selected sites.
            $query->whereHas('siteScope.sites')->whereDoesntHave('siteScope.sites', fn ($q) => $q->whereNotIn('site_id', $sites));
        }

        return $query;
    }

    private function roadmap(User $user, array $sites, string $id): array
    {
        if (! $user->canDo('roadmap.view')) {
            return $this->restricted();
        }
        $href = '/roadmap/initiatives';
        $initiatives = $this->initiatives($user, $sites, $id === 'governance-dependencies');
        $ids = (clone $initiatives)->select('roadmap_initiatives.id');
        if ($id === 'manager-improvements') {
            $query = (clone $initiatives)->whereNotIn('status', ['completed', 'cancelled']);
            $this->changes($query, 'Service initiative', $href);
            $total = (clone $query)->count();
            $rows = $query->orderByRaw('target_date IS NULL')->orderBy('target_date')->limit(6)->get(['id', 'title', 'target_date', 'status'])->map(fn ($r) => [
                'label' => $r->title, 'date' => $r->target_date?->toDateString(), 'status' => Str::headline($r->status), 'href' => $href.'/'.$r->id,
            ])->all();

            return $this->ready('Open roadmap initiatives · wholly within selected sites', $href, $rows, $total, 'No financial information is included');
        }
        if ($id === 'manager-owners') {
            $query = InitiativeTask::query()->whereIn('initiative_id', $ids)->whereNotIn('status', ['completed', 'cancelled'])
                ->where(fn ($q) => $q->whereNull('site_id')->orWhereIn('site_id', $sites));
            $total = (clone $query)->count();
            $unassigned = (clone $query)->whereNull('assignee_user_id')->count();

            return $this->ready('Open initiative tasks · selected sites', $href, [
                ['label' => 'Owner assigned', 'value' => $total - $unassigned], ['label' => 'Needs an owner', 'value' => $unassigned, 'tone' => 'warning'],
            ], $total, 'Ownership of roadmap actions only');
        }
        if ($id === 'manager-benefits') {
            $query = InitiativeBenefit::query()->whereIn('initiative_id', $ids);
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('updated_at')->limit(6)->get(['id', 'initiative_id', 'benefit_type', 'status'])->map(fn ($r) => [
                'label' => Str::headline($r->benefit_type).' outcome #'.$r->id, 'status' => Str::headline($r->status), 'href' => $href.'/'.$r->initiative_id,
            ])->all();

            return $this->ready('Recorded initiative benefit reviews · selected sites', $href, $rows, $total, 'Review status only; no financial baseline, target or outcome amounts');
        }
        $query = InitiativeDependency::query()->whereIn('initiative_id', $ids)
            ->where(fn ($q) => $q->whereNull('depends_on_initiative_id')->orWhereIn('depends_on_initiative_id', clone $ids));
        $this->changes($query, 'Strategic dependency', $href);
        $total = (clone $query)->count();
        $rows = $query->orderByRaw('due_date IS NULL')->orderBy('due_date')->limit(6)->get(['id', 'initiative_id', 'status', 'due_date', 'risk_level'])->map(fn ($r) => [
            'label' => 'Strategic dependency #'.$r->id, 'date' => $r->due_date?->toDateString(), 'status' => Str::headline($r->status), 'detail' => Str::headline($r->risk_level).' recorded risk', 'href' => $href.'/'.$r->initiative_id,
        ])->all();

        return $this->ready('Recorded roadmap dependencies · board view', $href, $rows, $total);
    }

    private function audit(User $user): array
    {
        if (! $user->canDo('governance.actions.view')) {
            return $this->restricted();
        }
        $query = app(GovernanceRecordAccessService::class)->scopeActionItems(ActionItem::query(), $user)->where('source_type', 'audit');
        $this->changes($query, 'Audit follow-up', '/governance/actions');

        return $this->ready('Governance actions recorded as audit findings', '/governance/actions', $this->groups($query), $query->count(), 'Recorded follow-through; completion does not constitute an audit opinion');
    }

    private function delegations(User $user): array
    {
        if (! Gate::forUser($user)->allows('viewAny', DecisionRequest::class)) {
            return $this->restricted();
        }
        $query = DecisionRequest::query()->whereNotNull('delegation_rule_id')->whereNull('resolved_at');
        $href = '/roadmap/decisions';
        $this->changes($query, 'Delegated decision', $href);
        $total = (clone $query)->count();
        $rows = $query->orderByRaw('due_date IS NULL')->orderBy('due_date')->limit(6)->get(['id', 'due_date', 'status', 'required_role'])->map(fn ($r) => [
            'label' => 'Delegated decision #'.$r->id, 'date' => $r->due_date?->toDateString(), 'status' => Str::headline($r->status), 'detail' => 'Required authority: '.Str::headline($r->required_role), 'href' => $href,
        ])->all();

        return $this->ready('Unresolved decisions linked to delegation rules', $href, $rows, $total, 'Open the decision workspace to verify authority and record a decision');
    }
}
