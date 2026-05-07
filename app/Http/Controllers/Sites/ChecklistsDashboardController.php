<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use Illuminate\Http\Request;

class ChecklistsDashboardController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('checklists.view'), 403);

        $today = now()->toDateString();
        $thirtyDaysAgo = now()->subDays(30);

        $stats = [
            'templates_active' => SiteChecklistTemplate::where('is_active', true)->count(),
            'templates_inactive' => SiteChecklistTemplate::where('is_active', false)->count(),
            'assignments_active' => SiteChecklistAssignment::where('is_active', true)->count(),
            'runs_scheduled' => SiteChecklistRun::where('status', 'scheduled')->count(),
            'runs_in_progress' => SiteChecklistRun::where('status', 'in_progress')->count(),
            'runs_overdue' => SiteChecklistRun::where('scheduled_date', '<', $today)
                ->whereIn('status', ['scheduled', 'in_progress'])
                ->count(),
            'runs_completed_30d' => SiteChecklistRun::where('status', 'completed')
                ->where('completed_at', '>=', $thirtyDaysAgo)
                ->count(),
            'sites_with_checklists' => SiteChecklistAssignment::where('is_active', true)
                ->distinct('site_id')
                ->count('site_id'),
        ];

        $activeRuns = SiteChecklistRun::query()
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->with([
                'site:id,name,type',
                'template:id,name,frequency',
            ])
            ->orderBy('scheduled_date')
            ->limit(50)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'status' => $run->status,
                'scheduled_date' => $run->scheduled_date?->toDateString(),
                'started_at' => $run->started_at?->toDateTimeString(),
                'completion_percentage' => (float) $run->completion_percentage,
                'is_overdue' => $run->scheduled_date && $run->scheduled_date->lt($today),
                'site' => $run->site ? [
                    'id' => $run->site->id,
                    'name' => $run->site->name,
                    'type' => $run->site->type,
                ] : null,
                'template' => $run->template ? [
                    'id' => $run->template->id,
                    'name' => $run->template->name,
                    'frequency' => $run->template->frequency,
                ] : null,
            ]);

        $recentRuns = SiteChecklistRun::query()
            ->where('status', 'completed')
            ->with([
                'site:id,name,type',
                'template:id,name',
                'completedBy:id,name',
            ])
            ->orderByDesc('completed_at')
            ->limit(15)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'completed_at' => $run->completed_at?->toDateTimeString(),
                'completion_percentage' => (float) $run->completion_percentage,
                'items_passed' => (int) $run->items_passed,
                'items_failed' => (int) $run->items_failed,
                'site' => $run->site ? [
                    'id' => $run->site->id,
                    'name' => $run->site->name,
                ] : null,
                'template' => $run->template ? [
                    'id' => $run->template->id,
                    'name' => $run->template->name,
                ] : null,
                'completed_by' => $run->completedBy?->only(['id', 'name']),
            ]);

        $assignments = SiteChecklistAssignment::query()
            ->where('is_active', true)
            ->with([
                'site:id,name,type',
                'template:id,name,frequency',
                'assignedTo:id,name',
            ])
            ->orderBy('site_id')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'frequency' => $a->frequency,
                'start_date' => $a->start_date?->toDateString(),
                'end_date' => $a->end_date?->toDateString(),
                'site' => $a->site ? [
                    'id' => $a->site->id,
                    'name' => $a->site->name,
                    'type' => $a->site->type,
                ] : null,
                'template' => $a->template ? [
                    'id' => $a->template->id,
                    'name' => $a->template->name,
                    'frequency' => $a->template->frequency,
                ] : null,
                'assigned_to' => $a->assignedTo?->only(['id', 'name']),
            ]);

        $templates = SiteChecklistTemplate::query()
            ->withCount(['items', 'assignments' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'key' => $t->key,
                'name' => $t->name,
                'description' => $t->description,
                'applicable_to_type' => $t->applicable_to_type,
                'frequency' => $t->frequency,
                'is_active' => (bool) $t->is_active,
                'items_count' => (int) $t->items_count,
                'assignments_count' => (int) $t->assignments_count,
            ]);

        $sitesOverview = Site::query()
            ->where('is_active', true)
            ->withCount([
                'checklistAssignments as active_assignments_count' => fn ($q) => $q->where('is_active', true),
                'checklistRuns as overdue_runs_count' => fn ($q) => $q
                    ->where('scheduled_date', '<', $today)
                    ->whereIn('status', ['scheduled', 'in_progress']),
                'checklistRuns as scheduled_runs_count' => fn ($q) => $q
                    ->where('status', 'scheduled'),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn ($site) => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'active_assignments' => (int) $site->active_assignments_count,
                'overdue_runs' => (int) $site->overdue_runs_count,
                'scheduled_runs' => (int) $site->scheduled_runs_count,
            ]);

        return inertia('checklists/index', [
            'stats' => $stats,
            'activeRuns' => $activeRuns,
            'recentRuns' => $recentRuns,
            'assignments' => $assignments,
            'templates' => $templates,
            'sitesOverview' => $sitesOverview,
            'can' => [
                'manageTemplates' => (bool) $request->user()?->canDo('checklists.manage_templates'),
                'schedule' => (bool) $request->user()?->canDo('checklists.schedule'),
                'run' => (bool) $request->user()?->canDo('checklists.run'),
            ],
        ]);
    }
}
