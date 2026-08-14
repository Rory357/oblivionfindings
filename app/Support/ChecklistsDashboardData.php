<?php

namespace App\Support;

use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistResponse;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the shared payload that powers BOTH the org-wide Checklists dashboard
 * (/checklists) and the per-site Checklists tab (/sites/{site}/checklists).
 *
 * The two surfaces are the same system scoped differently — never fork the
 * logic. forSite() simply constrains every query by site_id.
 */
class ChecklistsDashboardData
{
    public function __construct(private Request $request) {}

    public function forOrg(): array
    {
        return $this->build(null);
    }

    public function forSite(Site $site): array
    {
        return $this->build($site);
    }

    private function build(?Site $site): array
    {
        $siteId = $site?->id;
        $today = now()->toDateString();
        $user = $this->request->user();
        $canRun = (bool) $user?->canDo('checklists.run');
        $canSchedule = (bool) $user?->canDo('checklists.schedule');
        $canExecuteRun = static fn (SiteChecklistRun $run): bool => $user !== null
            && Gate::forUser($user)->allows('execute', $run);

        // ---- Templates (catalog is global in both modes) + a flags/meta map --
        $templateRows = SiteChecklistTemplate::query()
            ->withCount([
                'items',
                'assignments as assignments_count' => fn ($q) => $q->where('is_active', true),
                'items as hazard_items_count' => fn ($q) => $q->where('failure_creates_hazard', true),
                'items as photo_items_count' => fn ($q) => $q->where('response_type', 'photo'),
            ])
            ->orderBy('name')
            ->get();

        $meta = [];     // template_id => category/name/frequency/flags (reused by runs/assignments)
        $templates = $templateRows->map(function ($t) use (&$meta) {
            $settings = $t->settings ?? [];
            $flags = [
                'hazard' => (int) $t->hazard_items_count > 0,
                'photo' => (int) $t->photo_items_count > 0 || ! empty($settings['requires_photo']),
                'sign' => ! empty($settings['requires_signature']),
            ];
            $meta[$t->id] = [
                'category' => $t->category,
                'name' => $t->name,
                'frequency' => $t->frequency,
                'flags' => $flags,
            ];

            return [
                'id' => $t->id,
                'key' => $t->key,
                'name' => $t->name,
                'description' => $t->description,
                'category' => $t->category,
                'applicable_to_type' => $t->applicable_to_type,
                'frequency' => $t->frequency,
                'is_active' => (bool) $t->is_active,
                'items_count' => (int) $t->items_count,
                'assignments_count' => (int) $t->assignments_count,
                'flags' => $flags,
                'spotlight' => $t->key === 'quality_home_checklist',
            ];
        })->values();

        $tplOut = function (?SiteChecklistTemplate $t) use ($meta) {
            if (! $t) {
                return null;
            }
            $m = $meta[$t->id] ?? ['category' => null, 'flags' => ['hazard' => false, 'photo' => false, 'sign' => false]];

            return [
                'id' => $t->id,
                'name' => $t->name,
                'frequency' => $t->frequency,
                'category' => $m['category'],
                'flags' => $m['flags'],
            ];
        };

        // ---- Active runs (scheduled + in_progress; overdue derived) ----------
        $activeRuns = SiteChecklistRun::query()
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->with(['site:id,name,type', 'template:id,name,frequency', 'assignedTo:id,name', 'assignment.assignedTo:id,name'])
            ->orderBy('scheduled_date')
            ->limit(200)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'status' => $run->status,
                'can_run' => $canExecuteRun($run),
                'scheduled_date' => $run->scheduled_date?->toDateString(),
                'started_at' => $run->started_at?->toDateTimeString(),
                'pct' => (int) round((float) $run->completion_percentage),
                'completion_percentage' => (float) $run->completion_percentage,
                'is_overdue' => $run->scheduled_date && $run->scheduled_date->lt($today),
                'site' => $run->site ? ['id' => $run->site->id, 'name' => $run->site->name, 'type' => $run->site->type] : null,
                'template' => $tplOut($run->template),
                'assigned_to_id' => $run->assigned_to_user_id,
                'assignee' => $run->assignedTo?->name ?? $run->assignment?->assignedTo?->name ?? 'Unassigned',
            ])->values();

        // ---- Recent completed runs (history) ---------------------------------
        $recentRuns = SiteChecklistRun::query()
            ->where('status', 'completed')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->with(['site:id,name,type', 'template:id,name,frequency', 'completedBy:id,name'])
            ->orderByDesc('completed_at')
            ->limit(40)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'status' => 'completed',
                'can_run' => false,
                'completed_at' => $run->completed_at?->toDateTimeString(),
                'scheduled_date' => $run->completed_at?->toDateString(),
                'pct' => (int) round((float) $run->completion_percentage),
                'completion_percentage' => (float) $run->completion_percentage,
                'items_passed' => (int) $run->items_passed,
                'items_failed' => (int) $run->items_failed,
                'site' => $run->site ? ['id' => $run->site->id, 'name' => $run->site->name, 'type' => $run->site->type] : null,
                'template' => $tplOut($run->template),
                'assignee' => $run->completedBy?->name ?? 'Unassigned',
            ])->values();

        // ---- Skipped runs (restorable history) ------------------------------
        $skippedRuns = SiteChecklistRun::query()
            ->where('status', 'skipped')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->with(['site:id,name,type', 'template:id,name,frequency', 'assignedTo:id,name', 'assignment.assignedTo:id,name'])
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'status' => 'skipped',
                'can_run' => false,
                'scheduled_date' => $run->scheduled_date?->toDateString(),
                'started_at' => $run->started_at?->toDateTimeString(),
                'pct' => (int) round((float) $run->completion_percentage),
                'completion_percentage' => (float) $run->completion_percentage,
                'is_overdue' => false,
                'site' => $run->site ? ['id' => $run->site->id, 'name' => $run->site->name, 'type' => $run->site->type] : null,
                'template' => $tplOut($run->template),
                'assigned_to_id' => $run->assigned_to_user_id,
                'assignee' => $run->assignedTo?->name ?? $run->assignment?->assignedTo?->name ?? 'Unassigned',
            ])->values();

        // ---- Assignments (active) --------------------------------------------
        $assignments = SiteChecklistAssignment::query()
            ->where('is_active', true)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->with(['site:id,name,type', 'template:id,name,frequency', 'assignedTo:id,name'])
            ->orderBy('site_id')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'frequency' => $a->frequency,
                'site' => $a->site ? ['id' => $a->site->id, 'name' => $a->site->name, 'type' => $a->site->type] : null,
                'template' => $tplOut($a->template),
                'assignee' => $a->assignedTo?->name ?? 'Unassigned',
            ])->values();

        // ---- Sites overview (org only) ---------------------------------------
        $sitesOverview = [];
        if (! $siteId) {
            $completedBySite = $this->countBy('site_id', fn ($q) => $q
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->subDays(30)));
            $overdueBySite = $this->countBy('site_id', fn ($q) => $q
                ->where('scheduled_date', '<', $today)
                ->whereIn('status', ['scheduled', 'in_progress']));

            $sitesOverview = Site::query()
                ->where('is_active', true)
                ->withCount([
                    'checklistAssignments as active_assignments' => fn ($q) => $q->where('is_active', true),
                    'checklistRuns as overdue_runs' => fn ($q) => $q
                        ->where('scheduled_date', '<', $today)
                        ->whereIn('status', ['scheduled', 'in_progress']),
                    'checklistRuns as scheduled_runs' => fn ($q) => $q->where('status', 'scheduled'),
                ])
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(function ($s) use ($completedBySite, $overdueBySite) {
                    $c = (int) ($completedBySite[$s->id] ?? 0);
                    $o = (int) ($overdueBySite[$s->id] ?? 0);

                    return [
                        'id' => $s->id,
                        'name' => $s->name,
                        'type' => $s->type,
                        'active_assignments' => (int) $s->active_assignments,
                        'overdue_runs' => (int) $s->overdue_runs,
                        'scheduled_runs' => (int) $s->scheduled_runs,
                        'on_track_rate' => ($c + $o) === 0 ? 100 : (int) round($c * 100 / ($c + $o)),
                    ];
                })->values();
        }

        $reports = $this->reports($site);
        $rates = array_column($reports['complianceByCategory'], 'rate');
        $onTrack = count($rates) ? (int) round(array_sum($rates) / count($rates)) : 100;
        $completed30 = SiteChecklistRun::query()
            ->where('status', 'completed')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('completed_at', '>=', now()->subDays(30))
            ->count();
        $failures30 = SiteChecklistResponse::query()
            ->join('site_checklist_runs as r', 'r.id', '=', 'site_checklist_responses.run_id')
            ->where('site_checklist_responses.is_failed', true)
            ->when($siteId, fn ($q) => $q->where('r.site_id', $siteId))
            ->where('r.completed_at', '>=', now()->subDays(30))
            ->count();

        $stats = [
            'onTrack' => $onTrack,
            'overdue' => $activeRuns->where('is_overdue', true)->count(),
            'dueToday' => $activeRuns->where('scheduled_date', $today)->count(),
            'inProgress' => $activeRuns->where('status', 'in_progress')->count(),
            'scheduled' => $activeRuns->count(),
            'completed30' => $completed30,
            'failures' => $failures30,
        ];

        return [
            'categories' => config('checklists.categories'),
            'frequencyLabels' => config('checklists.frequency_labels'),
            'typeLabels' => config('checklists.type_labels'),
            'today' => $today,
            'templates' => $templates,
            'activeRuns' => $activeRuns,
            'recentRuns' => $recentRuns,
            'skippedRuns' => $skippedRuns,
            'assignments' => $assignments,
            'assignableUsers' => $this->assignableUsers($user),
            'sitesOverview' => $sitesOverview,
            'reports' => $reports,
            'stats' => $stats,
            'runDetail' => RunDetailPresenter::for(
                $this->request->integer('run'),
                $site?->id,
                $user,
            ),
            'templateDetail' => $this->templateDetail(),
            'can' => [
                'view' => (bool) $user?->canDo('checklists.view'),
                'manageTemplates' => (bool) $user?->canDo('checklists.manage_templates'),
                'schedule' => $canSchedule,
                'run' => $canRun,
            ],
        ];
    }

    /**
     * Org users selectable as a run assignee (the Schedule "Reassign" picker).
     * Scoped to the viewer's organisation so we never leak cross-tenant staff.
     */
    private function assignableUsers(?User $user): array
    {
        return User::query()
            ->when($user, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->whereNotNull('approved_at')
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }

    /**
     * Group run counts by a column, returning [value => count]. Scoping is left
     * to the caller's closure (org-wide here, since this is only used org-side).
     */
    private function countBy(string $column, callable $constrain): array
    {
        return $constrain(SiteChecklistRun::query())
            ->groupBy($column)
            ->pluck(DB::raw('count(*)'), $column)
            ->all();
    }

    private function reports(?Site $site): array
    {
        $siteId = $site?->id;
        $today = now()->toDateString();
        $categories = config('checklists.categories');

        // On-track by category — completed(30d) vs currently-overdue, per category.
        $catCompleted = $this->runsByCategory(fn ($q) => $q
            ->where('site_checklist_runs.status', 'completed')
            ->where('site_checklist_runs.completed_at', '>=', now()->subDays(30)), $siteId);
        $catOverdue = $this->runsByCategory(fn ($q) => $q
            ->where('site_checklist_runs.scheduled_date', '<', $today)
            ->whereIn('site_checklist_runs.status', ['scheduled', 'in_progress']), $siteId);

        $complianceByCategory = collect($categories)->map(function ($c) use ($catCompleted, $catOverdue) {
            $done = (int) ($catCompleted[$c['key']] ?? 0);
            $overdue = (int) ($catOverdue[$c['key']] ?? 0);

            return [
                'key' => $c['key'],
                'label' => $c['label'],
                'tone' => $c['tone'],
                'rate' => ($done + $overdue) === 0 ? 100 : (int) round($done * 100 / ($done + $overdue)),
                'overdue' => $overdue,
            ];
        })->values()->all();

        // 8-week completed-vs-overdue trend.
        $trend = [];
        $weekStart = Carbon::parse($today)->startOfWeek();
        for ($i = 7; $i >= 0; $i--) {
            $start = $weekStart->copy()->subWeeks($i);
            $end = $start->copy()->endOfWeek();
            $done = SiteChecklistRun::query()
                ->where('status', 'completed')
                ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
                ->whereBetween('completed_at', [$start, $end])
                ->count();
            $overdue = SiteChecklistRun::query()
                ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
                ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
                ->where('scheduled_date', '<', $today)
                ->where('status', '!=', 'completed')
                ->count();
            $trend[] = ['w' => $start->format('M j'), 'done' => $done, 'overdue' => $overdue];
        }

        // Top failing items → hazards raised.
        $topFailures = SiteChecklistResponse::query()
            ->join('site_checklist_template_items as i', 'i.id', '=', 'site_checklist_responses.template_item_id')
            ->join('site_checklist_templates as t', 't.id', '=', 'i.template_id')
            ->join('site_checklist_runs as r', 'r.id', '=', 'site_checklist_responses.run_id')
            ->where('site_checklist_responses.is_failed', true)
            ->when($siteId, fn ($q) => $q->where('r.site_id', $siteId))
            ->groupBy('i.id', 'i.question', 't.category')
            ->select(
                'i.question as item',
                't.category as cat',
                DB::raw('count(*) as count'),
                DB::raw('sum(case when site_checklist_responses.created_hazard_id is not null then 1 else 0 end) as hazards'),
            )
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'item' => $r->item,
                'cat' => $r->cat,
                'count' => (int) $r->count,
                'hazards' => (int) $r->hazards,
            ])->values()->all();

        return compact('complianceByCategory', 'trend', 'topFailures');
    }

    /**
     * Run counts grouped by the template's category. [category => count].
     */
    private function runsByCategory(callable $constrain, ?int $siteId): array
    {
        $q = SiteChecklistRun::query()
            ->join('site_checklist_templates as t', 't.id', '=', 'site_checklist_runs.template_id')
            ->when($siteId, fn ($qq) => $qq->where('site_checklist_runs.site_id', $siteId))
            ->whereNotNull('t.category');

        return $constrain($q)
            ->groupBy('t.category')
            ->pluck(DB::raw('count(*)'), 't.category')
            ->all();
    }

    /**
     * When the request carries ?template={id}, return that template's full
     * detail (incl. items + evidence flags) so the builder modal can edit it
     * without navigating to a separate page.
     */
    private function templateDetail(): ?array
    {
        $templateId = (int) $this->request->integer('template');
        if ($templateId <= 0) {
            return null;
        }

        $template = SiteChecklistTemplate::with(['items' => fn ($q) => $q->orderBy('sort_order')])
            ->withCount('assignments')
            ->find($templateId);

        if (! $template) {
            return null;
        }

        $settings = $template->settings ?? [];

        return [
            'id' => $template->id,
            'key' => $template->key,
            'name' => $template->name,
            'description' => $template->description,
            'category' => $template->category,
            'applicable_to_type' => $template->applicable_to_type,
            'frequency' => $template->frequency,
            'is_active' => (bool) $template->is_active,
            'requires_photo' => (bool) ($settings['requires_photo'] ?? false),
            'requires_signature' => (bool) ($settings['requires_signature'] ?? false),
            'assignments_count' => (int) $template->assignments_count,
            'items' => $template->items->map(fn ($item) => [
                'id' => $item->id,
                'question' => $item->question,
                'response_type' => $item->response_type,
                'response_config' => $item->response_config,
                'is_required' => (bool) $item->is_required,
                'guidance' => $item->guidance,
                'failure_creates_hazard' => (bool) $item->failure_creates_hazard,
                'failure_creates_damage' => (bool) $item->failure_creates_damage,
                'has_responses' => $item->responses()->exists(),
            ])->all(),
        ];
    }
}
