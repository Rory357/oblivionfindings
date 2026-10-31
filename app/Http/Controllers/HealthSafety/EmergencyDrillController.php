<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillAttachment;
use App\Models\EmergencyDrillFinding;
use App\Models\SiteEmergencyPlan;
use App\Services\HealthSafety\DrillComplianceService;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\EmergencyDrillConnectionsService;
use App\Services\HealthSafety\EmergencyDrillService;
use App\Support\HealthSafety\EmergencyDrillPresenter;
use App\Support\HealthSafety\EmergencyDrillReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmergencyDrillController extends Controller
{
    use ServesPrivateAttachments;

    private const TERMINAL_FINDINGS = ['resolved', 'closed', 'accepted'];

    public function __construct(private readonly DrillComplianceService $compliance, private readonly EmergencyDrillAccessService $access, private readonly EmergencyDrillService $service, private readonly EmergencyDrillPresenter $presenter, private readonly EmergencyDrillConnectionsService $connections) {}

    public function index(Request $request): Response|RedirectResponse
    {
        return $this->access->withCurrentViewer($request->user(), function ($current, $reads, $sites) use ($request) {
            abort_unless($current->canDo('hazards.view'), 403);
            if ($request->has('drill')) {
                $id = $request->query('drill');
                abort_unless(is_string($id) && preg_match('/^[1-9]\d*$/D', $id), 404);
                $target = $reads->query($this->access->scopeForSites(EmergencyDrill::query(), $sites))->findOrFail((int) $id);

                return redirect(EmergencyDrillReturn::href($target->id, EmergencyDrillReturn::fromRegister($request->query())));
            }
            $this->validateFilters($request);
            $tab = in_array($request->input('tab'), ['all', 'scheduled', 'overdue', 'in_progress', 'completed', 'findings'], true) ? $request->input('tab') : 'all';
            $base = $this->base($request, $sites);
            $query = $this->filtered(clone $base, $request, $tab);
            $origin = EmergencyDrillReturn::fromRegister($request->query());
            $drills = $query->orderByDesc('scheduled_at')->orderByDesc('id')->paginate(25)->withQueryString()
                ->through(fn ($record) => $this->presenter->row($record, $current, $origin));
            $counts = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
            $scheduled = (clone $base)->where('status', 'scheduled')->where('scheduled_at', '>=', now())->count();
            $overdue = (clone $base)->where('status', 'scheduled')->where('scheduled_at', '<', now())->count();
            $withFindings = (clone $base)->whereHas('findings', fn ($query) => $query->whereNotIn('status', self::TERMINAL_FINDINGS))->count();
            $findingQuery = EmergencyDrillFinding::query()->whereNotIn('status', self::TERMINAL_FINDINGS)->whereIn('emergency_drill_id', (clone $base)->select('id'));
            $openFindings = (clone $findingQuery)->count();
            $overdueFindings = (clone $findingQuery)->where('due_date', '<', now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString())->count();
            $summarySites = $request->filled('site_id') ? array_values(array_intersect($sites, [$request->integer('site_id')])) : $sites;
            $summary = $this->compliance->summary($summarySites);
            $plansDue = SiteEmergencyPlan::query()->whereIn('site_id', $summarySites)->where('status', 'active')->get()->filter(fn ($plan) => method_exists($plan, 'dueDate') && $plan->dueDate() !== null && Carbon::parse($plan->dueDate())->isPast())->count();
            $pickers = $this->presenter->pickers($current);

            return Inertia::render('health-safety/drills/index', [
                'drills' => $drills, 'tab' => $tab,
                'tabCounts' => ['all' => (int) $counts->sum(), 'scheduled' => $scheduled, 'overdue' => $overdue, 'in_progress' => (int) ($counts['in_progress'] ?? 0), 'completed' => (int) ($counts['completed'] ?? 0), 'findings' => $withFindings],
                'hero' => ['live' => ['scheduled' => $scheduled, 'overdue' => $overdue, 'in_progress' => (int) ($counts['in_progress'] ?? 0), 'completed' => (int) ($counts['completed'] ?? 0)],
                    'attention' => ['sites_overdue' => $summary['overdue'], 'findings_open' => $openFindings, 'findings_overdue' => $overdueFindings, 'awaiting_writeup' => (int) ($counts['in_progress'] ?? 0)],
                    'badges' => ['sites_drilled_pct' => $summary['pct'], 'drills_overdue' => $overdue, 'sites_overdue' => $summary['overdue'], 'fenz_reviews_due' => $plansDue]],
                'filters' => ['q' => $request->input('q'), 'tab' => $tab, 'period' => $request->input('period', '6mo'), 'drill_type' => $request->input('drill_type'), 'outcome' => $request->input('outcome'), 'site_id' => $request->filled('site_id') ? $request->integer('site_id') : null],
                'sites' => $pickers['sites'], 'staff' => $pickers['staff'], 'pickers' => $pickers, 'detail' => null,
                'can' => ['manage' => $current->canDo('hazards.manage'), 'schedule' => $this->access->canCreate($current)],
                'exports' => ['register_href' => '/health-safety/drills/export'.($request->query() === [] ? '' : '?'.http_build_query($request->except(['drill', 'schedule', 'action', 'return_to'])))],
            ]);
        });
    }

    public function create(Request $request): RedirectResponse
    {
        abort_unless($this->access->canCreate($request->user()), 403);

        return redirect()->route('health-safety.drills.index', ['schedule' => 1]);
    }

    public function store(Request $request): RedirectResponse
    {
        $row = $this->service->create($request->user(), $request->all());

        return $this->confirmed($request, $row, 'Emergency drill scheduled.', 'overview');
    }

    public function show(Request $request, EmergencyDrill $drill): Response|JsonResponse
    {
        $return = EmergencyDrillReturn::sanitize($request->query('return_to'));
        $detail = $this->presenter->detail($drill, $request->user(), $return);
        $props = ['detail' => $detail, 'pickers' => $this->presenter->pickers($request->user(), $drill), 'return_to' => $return, 'section' => in_array($request->input('section'), ['overview', 'run', 'participants', 'findings', 'evidence', 'history', 'connections'], true) ? $request->input('section') : 'overview'];

        return $request->expectsJson() ? response()->json($props) : Inertia::render('health-safety/drills/show', $props);
    }

    public function options(Request $request): JsonResponse
    {
        $request->validate(['site_id' => ['required', 'integer', 'min:1'], 'drill_id' => ['nullable', 'integer', 'min:1']]);

        return $this->access->withCurrentViewer($request->user(), function ($current, $reads, $sites) use ($request) {
            abort_unless($current->canDo('hazards.view') && in_array($request->integer('site_id'), $sites, true), 403);
            $record = $request->filled('drill_id') ? $reads->query($this->access->scopeForSites(EmergencyDrill::query(), $sites))->findOrFail($request->integer('drill_id')) : new EmergencyDrill(['site_id' => $request->integer('site_id')]);
            abort_unless((int) $record->site_id === $request->integer('site_id'), 404);

            return response()->json($this->presenter->pickers($current, $record));
        });
    }

    public function update(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        return $this->confirmed($request, $this->service->update($drill, $request->user(), $request->all()), 'Drill planning updated.', 'overview');
    }

    public function start(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        return $this->confirmed($request, $this->service->start($drill, $request->user(), $request->all()), 'Drill started.', 'run');
    }

    public function complete(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        return $this->confirmed($request, $this->service->complete($drill, $request->user(), $request->all()), 'Completion recorded.', 'run');
    }

    public function cancel(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        return $this->confirmed($request, $this->service->cancel($drill, $request->user(), $request->all()), 'Drill cancellation recorded.', 'run');
    }

    public function addParticipant(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        return $this->confirmed($request, $this->service->upsertParticipant($drill, $request->user(), $request->all()), 'Participant record saved.', 'participants');
    }

    public function addFinding(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        return $this->confirmed($request, $this->service->addFinding($drill, $request->user(), $request->all()), 'Finding recorded.', 'findings');
    }

    public function updateFinding(Request $request, EmergencyDrill $drill, EmergencyDrillFinding $finding): RedirectResponse
    {
        abort_unless((int) $finding->emergency_drill_id === (int) $drill->id, 404);

        return $this->confirmed($request, $this->service->updateFinding($drill, $finding, $request->user(), $request->all()), 'Finding updated.', 'findings', $finding->id);
    }

    public function updateLegacyFinding(Request $request, EmergencyDrillFinding $finding): RedirectResponse
    {
        return $this->updateFinding($request, $finding->emergencyDrill()->firstOrFail(), $finding);
    }

    public function resolveFinding(Request $request, EmergencyDrill $drill, EmergencyDrillFinding $finding): RedirectResponse
    {
        abort_unless((int) $finding->emergency_drill_id === (int) $drill->id, 404);

        return $this->confirmed($request, $this->service->resolveFinding($drill, $finding, $request->user(), $request->all()), 'Finding resolution recorded.', 'findings', $finding->id);
    }

    public function correctiveAction(Request $request, EmergencyDrill $drill, EmergencyDrillFinding $finding): RedirectResponse
    {
        abort_unless((int) $finding->emergency_drill_id === (int) $drill->id, 404);

        return $this->confirmed($request, $this->service->createOrLinkCorrectiveAction($drill, $finding, $request->user(), $request->all()), 'Required remedy linked.', 'findings', $finding->id);
    }

    public function correctiveActionOptions(Request $request, EmergencyDrill $drill, EmergencyDrillFinding $finding): JsonResponse
    {
        $row = $this->access->recordForViewer($drill, $request->user());
        abort_unless((int) $finding->emergency_drill_id === (int) $row->id, 404);
        $can = $this->access->findingCan($row, $finding, $request->user());
        abort_unless($can['create_action'] || $can['link_action'], 403);
        $connections = $this->connections->forViewer($row, $request->user());

        $staff = $this->presenter->pickers($request->user(), $row)['staff']->filter(fn ($candidate) => $this->access->withCurrentViewer($candidate, fn ($current) => $current->canDo('hazards.manage')))->values();

        return response()->json(['actions' => $connections['corrective_actions'], 'staff' => $staff, 'can' => ['create' => $can['create_action'], 'link' => $can['link_action']]]);
    }

    public function scheduleNext(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        return $this->confirmed($request, $this->service->scheduleNext($drill, $request->user(), $request->all()), 'Next drill scheduled.', 'connections');
    }

    public function uploadAttachment(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        return $this->confirmed($request, $this->service->addEvidence($drill, $request->user(), $request->all(), $request->file('file')), 'Evidence added.', 'evidence');
    }

    public function downloadAttachment(Request $request, EmergencyDrill $drill, EmergencyDrillAttachment $attachment): StreamedResponse
    {
        $this->access->recordForViewer($drill, $request->user());
        $file = EmergencyDrillAttachment::query()->where('emergency_drill_id', $drill->id)->findOrFail($attachment->id);
        abort_unless($file->disk === 'private', 404);

        return $this->streamPrivateAttachment('private', $file->path, basename(str_replace(chr(92), '/', $file->original_name)), $file->mime);
    }

    public function destroyAttachment(Request $request, EmergencyDrill $drill, EmergencyDrillAttachment $attachment): RedirectResponse
    {
        abort_unless((int) $attachment->emergency_drill_id === (int) $drill->id, 404);

        return $this->confirmed($request, $this->service->removeEvidence($drill, $attachment, $request->user(), $request->all()), 'Evidence removed from current view.', 'evidence');
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->access->withCurrentViewer($request->user(), function ($current, $reads, $sites) use ($request) {
            abort_unless($current->canDo('hazards.view'), 403);

            $this->validateFilters($request);

            return $this->filtered($this->base($request, $sites), $request, (string) $request->input('tab', 'all'))->with('site:id,name')->withCount(['participants as attended_count' => fn ($query) => $query->where('attended', true), 'findings as open_findings_count' => fn ($query) => $query->whereNotIn('status', self::TERMINAL_FINDINGS)])->orderByDesc('scheduled_at')->orderByDesc('id')->get()->map(fn ($row) => ['DR-'.$row->id, $row->title, $row->site?->name, $row->drill_type, $row->status, $row->outcome, $row->scheduled_at?->toIso8601String(), $row->completed_at?->toIso8601String(), (int) $row->attended_count, (int) $row->open_findings_count, '/health-safety/drills/'.$row->id])->all();
        });

        return $this->csv('emergency-drills.csv', ['Reference', 'Title', 'Site', 'Type', 'Status', 'Outcome', 'Scheduled instant', 'Completed instant', 'Actual attended', 'Open findings', 'Source'], $rows);
    }

    public function exportParticipants(Request $request, EmergencyDrill $drill): StreamedResponse
    {
        $detail = $this->presenter->detail($drill, $request->user());
        $rows = array_map(fn ($row) => [$detail['reference'], $row['name'], $row['role'], $row['attended'] ? 'Attended' : 'Not recorded as attended', $row['notes'], $row['accommodations']], $detail['participants']);

        return $this->csv('drill-'.$drill->id.'-participants.csv', ['Drill', 'Staff', 'Role', 'Attendance', 'Notes', 'Participation support'], $rows);
    }

    public function exportEvidence(Request $request, EmergencyDrill $drill): StreamedResponse
    {
        $detail = $this->presenter->detail($drill, $request->user());
        $rows = array_map(fn ($row) => [$detail['reference'], $row['original_name'], $row['kind'], $row['mime'], $row['size'], $row['notes'], $row['alt_text'], $row['created_at'], $row['url']], $detail['attachments']);

        return $this->csv('drill-'.$drill->id.'-evidence.csv', ['Drill', 'Name', 'Kind', 'Type', 'Bytes', 'Notes', 'Description', 'Recorded instant', 'Authorized download'], $rows);
    }

    private function confirmed(Request $request, EmergencyDrill $row, string $message, string $section, ?int $findingId = null): RedirectResponse
    {
        $requestedSection = $request->input('section');
        $href = EmergencyDrillReturn::href($row->id, $request->input('return_to'), is_string($requestedSection) ? $requestedSection : $section, $findingId);
        $action = $request->input('action');
        $aliases = ['participant' => 'add-participant', 'add_participant' => 'add-participant', 'edit_participant' => 'edit-participant',
            'finding' => 'add-finding', 'add_finding' => 'add-finding', 'edit_finding' => 'edit-finding', 'resolve' => 'resolve-finding', 'resolve_finding' => 'resolve-finding',
            'corrective_action' => 'corrective-action', 'schedule_next' => 'schedule-next'];
        $action = is_string($action) ? ($aliases[$action] ?? $action) : null;
        if (in_array($action, ['edit', 'start', 'complete', 'cancel', 'add-participant', 'edit-participant', 'add-finding', 'edit-finding', 'resolve-finding', 'corrective-action', 'schedule-next'], true)) {
            $href .= (str_contains($href, '?') ? '&' : '?').'action='.rawurlencode($action);
            $participant = $request->input('participant');
            if ($action === 'edit-participant' && (is_int($participant) || (is_string($participant) && ctype_digit($participant))) && $row->participants()->whereKey((int) $participant)->exists()) {
                $href .= '&participant='.(int) $participant;
            }
        }

        return redirect($href)->with('success', $message);
    }

    private function validateFilters(Request $request): void
    {
        $request->validate(['site_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:200'],
            'tab' => ['nullable', Rule::in(['all', 'scheduled', 'overdue', 'in_progress', 'completed', 'findings'])],
            'period' => ['nullable', Rule::in(['30d', 'quarter', '6mo', 'all'])],
            'drill_type' => ['nullable', Rule::in([...EmergencyDrillService::DRILL_TYPES, 'fire'])],
            'outcome' => ['nullable', Rule::in(['passed', 'passed_actions', 'failed'])]]);
    }

    private function base(Request $request, array $sites): Builder
    {
        $query = $this->access->scopeForSites(EmergencyDrill::query(), $sites);
        if ($request->filled('site_id')) {
            $query->where('site_id', $request->integer('site_id'));
        }
        $from = match ((string) $request->input('period', '6mo')) {
            '30d' => now()->subDays(30), 'quarter' => now()->subMonths(3), 'all' => null, default => now()->subMonths(6),
        };

        return $from ? $query->where('scheduled_at', '>=', $from) : $query;
    }

    private function filtered(Builder $query, Request $request, string $tab): Builder
    {
        match ($tab) {
            'scheduled' => $query->where('status', 'scheduled')->where('scheduled_at', '>=', now()),
            'overdue' => $query->where('status', 'scheduled')->where('scheduled_at', '<', now()),
            'in_progress', 'completed' => $query->where('status', $tab),
            'findings' => $query->whereHas('findings', fn ($q) => $q->whereNotIn('status', self::TERMINAL_FINDINGS)),
            default => $query,
        };
        if ($request->filled('drill_type')) {
            $type = $request->input('drill_type');
            in_array($type, ['fire', 'fire_evacuation'], true) ? $query->whereIn('drill_type', ['fire', 'fire_evacuation']) : $query->where('drill_type', $type);
        }
        if ($request->filled('outcome')) {
            $query->where('outcome', $request->input('outcome'));
        }
        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.trim((string) $request->input('q')).'%')->orWhere('drill_type', 'like', '%'.trim((string) $request->input('q')).'%'));
        }

        return $query;
    }

    private function csv(string $name, array $columns, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, $columns);
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn ($value) => is_string($value) && preg_match('/^[\s]*[=+@-]/', $value) ? "'".$value : $value, $row));
            }
            fclose($stream);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
