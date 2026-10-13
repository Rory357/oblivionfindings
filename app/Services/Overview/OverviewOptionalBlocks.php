<?php

namespace App\Services\Overview;

use App\Domain\Governance\Models\BoardMemberInterest;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Services\HrLifecycleAccessService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKbRevisionService;
use App\Models\ClientNote;
use App\Models\ItKbArticle;
use App\Models\User;
use App\Services\Fleet\MaintenanceAccessService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Small read-only projections over existing module boundaries. */
final class OverviewOptionalBlocks
{
    public function __construct(private readonly UserSiteAccessService $sites) {}

    private function ready(string $subtitle, string $href, array $rows, int $total, string $footnote): array
    {
        return ['state' => 'ready', 'subtitle' => $subtitle, 'href' => $href, 'rows' => $rows, 'series' => [], 'total' => $total, 'footnote' => $footnote];
    }

    private function restricted(): array
    {
        return ['state' => 'restricted', 'subtitle' => 'Permission required', 'message' => 'This summary is not available with your current permissions.', 'rows' => [], 'series' => [], 'total' => null];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
    }

    private function selectedStaffSites(Builder $profile, array $siteIds): void
    {
        $profile->where(function ($scope) use ($siteIds) {
            $scope->whereIn('primary_site_id', $siteIds);
            foreach ($siteIds as $id) {
                $scope->orWhereJsonContains('secondary_site_ids', $id);
            }
        });
    }

    public function drafts(User $user, array $siteIds): array
    {
        if (! $user->canDo('progress_notes.viewAny') || ! $user->canDo('progress_notes.create')
            || (! $user->canDo('clients.viewAny') && ! $user->canDo('clients.viewAssigned'))) {
            return $this->restricted();
        }
        $query = ClientNote::query()->dailyNotes()->forUser($user)->where('user_id', $user->id)->where('is_draft', true)
            ->whereHas('client', function ($clients) use ($user, $siteIds) {
                $this->sites->applyClientScope($clients, $user, $user->canDo('clients.viewAny') ? ['clinical.accessAllSites', 'sites.viewAll'] : [])
                    ->whereIn('site_id', $siteIds);
                if (! $user->canDo('clients.viewAny')) {
                    $clients->whereHas('supportWorkers', fn ($staff) => $staff->where('users.id', $user->id));
                }
            })
            ->where(fn ($notes) => $notes->whereNull('shift_id')->orWhereHas('shift', fn ($shift) => $this->sites->applyShiftScope($shift, $user, [])
                ->whereColumn('shifts.client_id', 'client_notes.client_id')));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('updated_at')->limit(6)->get(['id', 'client_id', 'updated_at'])->map(fn ($note) => [
            'label' => 'Daily note draft #'.$note->id, 'date' => $note->updated_at?->toIso8601String(), 'status' => 'Draft',
            'detail' => 'Last saved · open Daily Notes to continue', 'href' => '/operations/clients/'.$note->client_id.'?tab=progress_notes',
        ])->all();

        return $this->ready('Your saved daily notes · selected permitted sites', '/operations/clients', $rows, $total, 'Own drafts only · note contents stay in the client workspace');
    }

    public function supervision(User $user, array $siteIds): array
    {
        if (! $user->canDo('hr.performance.view')) {
            return $this->restricted();
        }
        $access = app(HrPerformanceAccessService::class);
        $query = $access->applyCurrentSubjectScope(HrSupervisionNote::query(), $user)
            ->whereHas('employee.hrEmployeeProfile', fn ($profile) => $this->selectedStaffSites($profile, $siteIds))
            // An older note's next date stops being a pending follow-up once a newer session is recorded.
            ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('hr_supervision_notes as newer')
                ->whereColumn('newer.employee_user_id', 'hr_supervision_notes.employee_user_id')
                ->where(fn ($date) => $date->whereColumn('newer.session_date', '>', 'hr_supervision_notes.session_date')
                    ->orWhere(fn ($same) => $same->whereColumn('newer.session_date', 'hr_supervision_notes.session_date')->whereColumn('newer.id', '>', 'hr_supervision_notes.id'))))
            ->whereNotNull('next_session_date')->whereDate('next_session_date', '<=', $this->today()->addDays(30)->toDateString());
        $total = (clone $query)->count();
        $today = $this->today()->toDateString();
        $href = '/hr/performance?tab=supervision';
        $rows = $query->with('employee:id,name')->orderBy('next_session_date')->limit(6)->get(['id', 'employee_user_id', 'next_session_date'])->map(fn ($note) => [
            'label' => $note->employee?->name ?? 'Supervision follow-up #'.$note->id, 'date' => $note->next_session_date->toDateString(),
            'status' => $note->next_session_date->toDateString() < $today ? 'Date passed' : 'Upcoming',
            'tone' => $note->next_session_date->toDateString() < $today ? 'warning' : 'neutral',
            'detail' => 'Recorded next supervision date', 'href' => $href,
        ])->all();

        return $this->ready('Recorded next-session dates · overdue and next 30 days', $href, $rows, $total, 'Latest session per current staff member · discussion notes remain private')
            + ['title' => 'Supervision follow-ups'];
    }

    public function leavers(User $user, array $siteIds): array
    {
        if (! $user->canDo('hr.onboarding.view')) {
            return $this->restricted();
        }
        $query = app(HrLifecycleAccessService::class)->visibleOffboardingChecklists($user)
            ->whereHas('employeeProfile', fn ($profile) => $this->selectedStaffSites($profile, $siteIds))
            ->whereIn('status', ['pending', 'in_progress']);
        $total = (clone $query)->count();
        $today = $this->today()->toDateString();
        $rows = $query->withCount(['tasks', 'tasks as done_count' => fn ($tasks) => $tasks->where('status', 'completed')])
            ->orderByRaw('due_date IS NULL')->orderBy('due_date')->limit(6)->get(['id', 'due_date', 'status'])->map(fn ($checklist) => [
                'label' => 'Leaver handover #'.$checklist->id, 'date' => $checklist->due_date?->toDateString(),
                'value' => (int) $checklist->done_count, 'target' => (int) $checklist->tasks_count,
                'detail' => $checklist->tasks_count ? $checklist->done_count.' of '.$checklist->tasks_count.' tasks complete' : 'Checklist tasks not configured',
                'status' => $checklist->due_date && $checklist->due_date->toDateString() < $today ? 'Overdue' : Str::headline($checklist->status),
                'tone' => $checklist->due_date && $checklist->due_date->toDateString() < $today ? 'warning' : 'neutral',
                'href' => '/hr/offboarding/'.$checklist->id,
            ])->all();

        return $this->ready('Open offboarding checklists · permitted staff', '/hr/offboarding', $rows, $total, 'Practical handover tasks · departure reasons stay in HR');
    }

    public function maintenance(User $user, array $siteIds): array
    {
        $access = app(MaintenanceAccessService::class);
        if (! $access->canRead($user) || (! $user->canDo('fleet.viewAny') && ! $user->canDo('assets.viewAny'))) {
            return $this->restricted();
        }
        $query = $access->scopedWorkOrders($user)->whereHas('asset', fn ($asset) => $asset->whereIn('site_id', $siteIds))
            ->whereNotIn('status', ['completed', 'cancelled']);
        $total = (clone $query)->count();
        $urgent = (clone $query)->where('priority', 'urgent')->count();
        $overdue = (clone $query)->whereNotNull('due_at')->where('due_at', '<', now())->count();
        $href = '/fleet-assets/maintenance/work-orders'.(count($siteIds) === 1 ? '?site_id='.$siteIds[0] : '');
        $rows = $query->select('status')->selectRaw('COUNT(*) AS aggregate')->groupBy('status')->orderByDesc('aggregate')->get()
            ->map(fn ($group) => ['label' => Str::headline($group->status), 'value' => (int) $group->aggregate])->all();

        return $this->ready('Open fleet and equipment work orders · selected sites', $href, $rows, $total, $urgent.' urgent · '.$overdue.' overdue · counts may overlap');
    }

    public function knowledge(User $user, array $siteIds): array
    {
        $access = app(ItKbAccessService::class);
        if (! $access->canReviewRecords($user)) {
            return $this->restricted();
        }
        $query = app(ItKbRevisionService::class)->applyOriginalScope($access->applyViewScope(ItKbArticle::query(), $user), $user)
            ->published()->whereNotNull('review_due_at')->whereDate('review_due_at', '<=', $this->today()->addDays(30)->toDateString())
            ->where(function ($scope) use ($siteIds) {
                $scope->whereIn('audience', ['all_staff', 'it_agents'])->orWhere(function ($specific) use ($siteIds) {
                    $specific->where('audience', 'specific_sites')->where(function ($sites) use ($siteIds) {
                        if ($siteIds === []) {
                            $sites->whereRaw('1 = 0');
                        }
                        foreach ($siteIds as $id) {
                            $sites->orWhereJsonContains('site_scope', $id)->orWhereJsonContains('site_scope', (string) $id);
                        }
                    });
                });
            });
        $total = (clone $query)->count();
        $today = $this->today()->toDateString();
        $rows = $query->orderBy('review_due_at')->limit(6)->get(['id', 'title', 'review_due_at'])->map(fn ($article) => [
            'label' => $article->title, 'date' => $article->review_due_at->toDateString(),
            'status' => $article->review_due_at->toDateString() < $today ? 'Review overdue' : 'Review coming up',
            'tone' => $article->review_due_at->toDateString() < $today ? 'warning' : 'neutral',
            'href' => '/it/knowledge/'.$article->id,
        ])->all();

        return $this->ready('Published article reviews · overdue and next 30 days', '/it/knowledge', $rows, $total, 'Organisation articles and selected sites · within your review authority');
    }

    public function declarations(User $user): array
    {
        if (! $user->canDo('governance.interests.view')) {
            return $this->restricted();
        }
        $member = $user->boardMember;
        $query = BoardMemberInterest::query()->where('board_member_id', $member?->id ?? 0)->current();
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('declared_at')->limit(6)->get(['id', 'declared_at'])->map(fn ($interest) => [
            'label' => 'Your declaration #'.$interest->id, 'date' => $interest->declared_at?->toDateString(),
            'status' => 'Current', 'detail' => 'Declared on · open your private view to check the details', 'href' => '/governance/interests/mine',
        ])->all();

        return $this->ready($member ? 'Your current declarations · personal board work' : 'No board-member profile is linked to your account', '/governance/interests/mine', $rows, $total, 'Own declarations only · interests and related people are not shown');
    }
}
