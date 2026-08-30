<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\Concerns\ResolvesAllowedSiteTypes;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Services\Facility\FacilitySignalService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SiteInspectionController extends Controller
{
    use ResolvesAllowedSiteTypes;

    private const SITE_BYPASS_PERMISSIONS = ['sites.viewAll'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $schedules = SiteInspectionSchedule::where('site_id', $site->id)
            ->with('assignedTo:id,name')
            ->orderBy('next_due_date')
            ->get();

        $records = SiteInspectionRecord::where('site_id', $site->id)
            ->with(['schedule', 'completedBy:id,name'])
            ->orderByDesc('due_date')
            ->paginate(20);

        return inertia('sites/inspections/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'schedules' => $schedules,
            'records' => $records,
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'inspection_type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'frequency' => 'required|in:weekly,monthly,quarterly,bi_annual,annual,custom',
            'custom_rrule' => 'nullable|string',
            'first_due_date' => 'required|date',
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'auto_create_calendar_event' => 'boolean',
        ]);

        $schedule = SiteInspectionSchedule::create([
            ...$validated,
            'site_id' => $site->id,
            'next_due_date' => $validated['first_due_date'],
            'is_active' => true,
        ]);

        // Create calendar event if requested
        if ($validated['auto_create_calendar_event'] ?? true) {
            SiteCalendarEvent::create([
                'site_id' => $site->id,
                'event_type' => 'inspection',
                'title' => $validated['title'],
                'description' => $validated['description'] ?? 'Scheduled inspection',
                'start_at' => $validated['first_due_date'].' 09:00:00',
                'end_at' => $validated['first_due_date'].' 10:00:00',
                'recurrence_rule' => $this->frequencyToRrule($validated['frequency'], $validated['custom_rrule'] ?? null),
                'created_by_user_id' => $request->user()->id,
                'owner_user_id' => $validated['assigned_to_user_id'] ?? null,
                'status' => 'draft',
            ]);
        }

        return redirect()
            ->route('sites.inspections.index', $site)
            ->with('success', 'Inspection schedule created.');
    }

    public function complete(Request $request, Site $site, SiteInspectionSchedule $schedule)
    {
        $this->authorize('update', $site);
        abort_unless($schedule->site_id === $site->id, 404);

        $validated = $request->validate([
            'result' => 'required|in:pass,fail,partial,na',
            'findings' => 'nullable|string',
            'corrective_actions' => 'nullable|string',
            'evidence_photos' => 'nullable|array',
            'linked_hazard_id' => [
                'nullable',
                Rule::exists('site_hazards', 'id')
                    ->where(fn ($query) => $query
                        ->where('site_id', $site->id)
                        ->whereNull('deleted_at')),
            ],
        ]);

        DB::transaction(function () use ($request, $site, $schedule, $validated): void {
            $lockedSchedule = SiteInspectionSchedule::query()
                ->whereKey($schedule->id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            $record = SiteInspectionRecord::create([
                'schedule_id' => $lockedSchedule->id,
                'site_id' => $site->id,
                'due_date' => $lockedSchedule->next_due_date,
                'completed_at' => now(),
                'completed_by_user_id' => $request->user()->id,
                'result' => $validated['result'],
                'findings' => $validated['findings'] ?? null,
                'corrective_actions' => $validated['corrective_actions'] ?? null,
                'linked_hazard_id' => $validated['linked_hazard_id'] ?? null,
                'evidence_photos' => $validated['evidence_photos'] ?? null,
            ]);

            // A failed inspection and its durable Control Room delivery intent
            // are one acceptance boundary. Any persistence failure rolls back
            // the record and schedule advance together.
            if ($record->result === 'fail') {
                app(FacilitySignalService::class)->emitInspectionFailed($lockedSchedule, $record);
            }

            $lockedSchedule->update([
                'next_due_date' => $this->calculateNextDueDate($lockedSchedule),
            ]);
        }, 3);

        return redirect()
            ->route('sites.inspections.index', $site)
            ->with('success', 'Inspection recorded successfully.');
    }

    public function destroy(Request $request, Site $site, SiteInspectionSchedule $schedule)
    {
        $this->authorize('update', $site);
        abort_unless($schedule->site_id === $site->id, 404);

        $schedule->delete();

        return redirect()
            ->route('sites.inspections.index', $site)
            ->with('success', 'Inspection schedule deleted.');
    }

    public function globalIndex(Request $request)
    {
        abort_unless($request->user()?->canDo('checklists.view'), 403);
        $this->authorize('viewAny', Site::class);

        $allowedSiteTypes = $this->allowedSiteTypes($request);
        if ($request->filled('site_id')) {
            $this->siteAccess->assertCanAccessSiteId(
                $request->user(),
                (int) $request->query('site_id'),
                self::SITE_BYPASS_PERMISSIONS,
            );
        }
        $accessibleSiteIds = $this->siteAccess->accessibleSiteIds(
            $request->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );

        $schedules = SiteInspectionSchedule::query()
            ->with(['site:id,name,type', 'assignedTo:id,name'])
            ->whereIn('site_id', $accessibleSiteIds)
            ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
            ->when($request->site_id, fn ($q) => $q->where('site_id', (int) $request->site_id))
            ->when($request->inspection_type, fn ($q) => $q->where('inspection_type', $request->inspection_type))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->due_state === 'overdue', fn ($q) => $q->whereDate('next_due_date', '<', now()->toDateString()))
            ->when($request->due_state === 'due_soon', fn ($q) => $q->whereBetween('next_due_date', [now()->toDateString(), now()->addDays(7)->toDateString()]))
            ->orderBy('next_due_date')
            ->limit(500)
            ->get()
            ->map(fn (SiteInspectionSchedule $schedule) => [
                'id' => $schedule->id,
                'site_id' => $schedule->site_id,
                'site_name' => $schedule->site?->name,
                'site_type' => $schedule->site?->type,
                'inspection_type' => $schedule->inspection_type,
                'title' => $schedule->title,
                'frequency' => $schedule->frequency,
                'next_due_date' => $schedule->next_due_date?->toDateString(),
                'is_active' => (bool) $schedule->is_active,
                'assigned_to_name' => $schedule->assignedTo?->name,
            ])
            ->values();

        $records = SiteInspectionRecord::query()
            ->with(['site:id,name,type', 'completedBy:id,name', 'schedule:id,title'])
            ->whereIn('site_id', $accessibleSiteIds)
            ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
            ->when($request->site_id, fn ($q) => $q->where('site_id', (int) $request->site_id))
            ->when($request->result, fn ($q) => $q->where('result', $request->result))
            ->orderByDesc('completed_at')
            ->orderByDesc('due_date')
            ->limit(500)
            ->get()
            ->map(fn (SiteInspectionRecord $record) => [
                'id' => $record->id,
                'site_id' => $record->site_id,
                'site_name' => $record->site?->name,
                'site_type' => $record->site?->type,
                'schedule_title' => $record->schedule?->title,
                'due_date' => $record->due_date?->toDateString(),
                'completed_at' => $record->completed_at?->toDateTimeString(),
                'completed_by_name' => $record->completedBy?->name,
                'result' => $record->result,
                'findings' => $record->findings,
            ])
            ->values();

        $sites = Site::query()
            ->active()
            ->whereIn('type', $allowedSiteTypes)
            ->select(['id', 'name', 'type'])
            ->orderBy('name');
        $this->siteAccess->applySiteScope(
            $sites,
            $request->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );

        $inspectionTypes = SiteInspectionSchedule::query()
            ->whereIn('site_id', $accessibleSiteIds)
            ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
            ->select('inspection_type')
            ->distinct()
            ->orderBy('inspection_type')
            ->pluck('inspection_type')
            ->values();

        return inertia('sites/inspections/global', [
            'schedules' => $schedules,
            'records' => $records,
            'sites' => $sites->get(),
            'inspectionTypes' => $inspectionTypes,
            'filters' => $request->only(['site_id', 'inspection_type', 'status', 'due_state', 'result']),
        ]);
    }

    private function frequencyToRrule(string $frequency, ?string $custom): ?string
    {
        if ($custom) {
            return $custom;
        }

        return match ($frequency) {
            'weekly' => 'FREQ=WEEKLY;INTERVAL=1',
            'monthly' => 'FREQ=MONTHLY;INTERVAL=1',
            'quarterly' => 'FREQ=MONTHLY;INTERVAL=3',
            'bi_annual' => 'FREQ=MONTHLY;INTERVAL=6',
            'annual' => 'FREQ=YEARLY;INTERVAL=1',
            default => null,
        };
    }

    private function calculateNextDueDate(SiteInspectionSchedule $schedule): string
    {
        $current = Carbon::parse($schedule->next_due_date);

        return match ($schedule->frequency) {
            'weekly' => $current->addWeek()->toDateString(),
            'monthly' => $current->addMonth()->toDateString(),
            'quarterly' => $current->addMonths(3)->toDateString(),
            'bi_annual' => $current->addMonths(6)->toDateString(),
            'annual' => $current->addYear()->toDateString(),
            default => $current->addMonth()->toDateString(),
        };
    }
}
