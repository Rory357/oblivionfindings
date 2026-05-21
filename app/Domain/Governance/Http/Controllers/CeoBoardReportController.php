<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\CeoBoardReport;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Services\DashboardAggregatorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CeoBoardReportController extends Controller
{
    public function __construct(protected DashboardAggregatorService $aggregator) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', CeoBoardReport::class);

        $reports = CeoBoardReport::with(['meeting', 'submittedBy'])
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(fn (CeoBoardReport $report) => $this->presentReport($report, brief: true));

        $meetings = $this->meetingOptions();

        return Inertia::render('Governance/CeoReports/Index', [
            'reports' => $reports,
            'meetings' => $meetings,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', CeoBoardReport::class);

        return Inertia::render('Governance/CeoReports/Create', [
            'meetings' => $this->meetingOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', CeoBoardReport::class);

        $validated = $this->validateReport($request, creating: true);

        $report = CeoBoardReport::create([
            ...$validated,
            'submitted_by' => auth()->id(),
            'status' => CeoBoardReport::STATUS_DRAFT,
        ]);

        if ($request->boolean('submit_immediately')) {
            $report->submit($this->captureKpiSnapshot());
        }

        return $request->wantsJson()
            ? response()->json(['id' => $report->id, 'status' => $report->status])
            : redirect()
                ->route('governance.ceo-reports.show', $report)
                ->with('success', 'CEO report created.');
    }

    public function show(CeoBoardReport $report)
    {
        $this->authorize('view', $report);

        $report->load(['meeting', 'submittedBy', 'presentedBy']);

        return Inertia::render('Governance/CeoReports/Show', [
            'report' => $this->presentReport($report),
            'meetings' => $this->meetingOptions(includeReportMeeting: $report),
        ]);
    }

    public function update(Request $request, CeoBoardReport $report)
    {
        $this->authorize('update', $report);

        $validated = $this->validateReport($request, creating: false);
        // Cannot reassign the meeting after creation (one report per meeting unique constraint).
        unset($validated['governance_meeting_id']);

        $report->update($validated);

        return $request->wantsJson()
            ? response()->json(['id' => $report->id])
            : redirect()->back()->with('success', 'CEO report updated.');
    }

    public function submit(CeoBoardReport $report)
    {
        $this->authorize('submit', $report);

        $report->submit($this->captureKpiSnapshot());

        return redirect()->back()->with('success', 'CEO report submitted to board.');
    }

    public function markPresented(CeoBoardReport $report)
    {
        $this->authorize('submit', $report);

        $report->markPresented(auth()->user());

        return redirect()->back()->with('success', 'CEO report marked as presented.');
    }

    public function kpiSnapshot()
    {
        $this->authorize('viewAny', CeoBoardReport::class);

        return response()->json([
            'kpi_snapshot' => $this->captureKpiSnapshot(),
            'captured_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Upload one or more files and append them to the report's attachments JSON.
     */
    public function attachFiles(Request $request, CeoBoardReport $report)
    {
        $this->authorize('update', $report);

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => [
                'required',
                'file',
                'max:20480', // 20 MB per file
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt,md',
            ],
        ]);

        $existing = is_array($report->attachments) ? $report->attachments : [];

        foreach ($request->file('files') as $file) {
            $directory = "governance/ceo-reports/{$report->id}";
            $extension = $file->getClientOriginalExtension() ?: $file->extension();
            $storedName = Str::uuid()->toString() . ($extension ? ".{$extension}" : '');
            $path = $file->storeAs($directory, $storedName, 'local');

            $existing[] = [
                'id' => Str::uuid()->toString(),
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_at' => now()->toIso8601String(),
                'uploaded_by_id' => auth()->id(),
                'uploaded_by_name' => auth()->user()?->name,
            ];
        }

        $report->update(['attachments' => $existing]);

        return $request->wantsJson()
            ? response()->json(['attachments' => $existing])
            : redirect()->back()->with('success', 'Attachment(s) uploaded.');
    }

    /**
     * Remove one attachment by id (removes both the storage file and the JSON entry).
     */
    public function deleteAttachment(Request $request, CeoBoardReport $report, string $attachment)
    {
        $this->authorize('update', $report);

        $existing = is_array($report->attachments) ? $report->attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target) {
            abort(404, 'Attachment not found.');
        }

        if (isset($target['path']) && Storage::disk('local')->exists($target['path'])) {
            Storage::disk('local')->delete($target['path']);
        }

        $remaining = array_values(
            array_filter($existing, fn (array $row) => ($row['id'] ?? null) !== $attachment)
        );

        $report->update(['attachments' => $remaining]);

        return $request->wantsJson()
            ? response()->json(['attachments' => $remaining])
            : redirect()->back()->with('success', 'Attachment removed.');
    }

    /**
     * Stream a stored attachment back to the user with its original filename.
     */
    public function downloadAttachment(CeoBoardReport $report, string $attachment)
    {
        $this->authorize('view', $report);

        $existing = is_array($report->attachments) ? $report->attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target || empty($target['path']) || ! Storage::disk('local')->exists($target['path'])) {
            abort(404, 'Attachment not found.');
        }

        return Storage::disk('local')->download(
            $target['path'],
            $target['original_name'] ?? 'attachment',
            ['Content-Type' => $target['mime_type'] ?? 'application/octet-stream'],
        );
    }

    protected function validateReport(Request $request, bool $creating): array
    {
        $rules = [
            'governance_meeting_id' => ($creating ? 'required' : 'sometimes') . '|exists:governance_meetings,id',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'deadline' => 'nullable|date',
            'executive_summary' => 'nullable|string',
            'operational_summary' => 'nullable|string',
            'key_achievements' => 'nullable|string',
            'challenges_and_risks' => 'nullable|string',
            'staffing_update' => 'nullable|string',
            'compliance_status' => 'nullable|string',
            'financial_summary' => 'nullable|string',
            'recommendations' => 'nullable|string',
            'decisions_sought' => 'nullable|array',
            'decisions_sought.*.title' => 'nullable|string|max:255',
            'decisions_sought.*.detail' => 'nullable|string',
            'decisions_sought.*.recommendation' => 'nullable|string',
            'matters_arising' => 'nullable|array',
            'matters_arising.*.title' => 'nullable|string|max:255',
            'matters_arising.*.status' => 'nullable|string|max:50',
            'matters_arising.*.update' => 'nullable|string',
        ];

        return $request->validate($rules);
    }

    /**
     * Snapshot of the aggregator widgets at submission time. Wrapped in
     * try/catch so a failing widget doesn't block the submit.
     */
    protected function captureKpiSnapshot(): ?array
    {
        try {
            $range = ['start' => now()->subDays(30), 'end' => now()];

            return [
                'captured_at' => now()->toIso8601String(),
                'top_risks' => $this->safe(fn () => $this->aggregator->getTopRisks(10)),
                'compliance_calendar' => $this->safe(fn () => $this->aggregator->getComplianceCalendar()),
                'incidents' => $this->safe(fn () => $this->aggregator->getIncidentMetrics($range)),
                'financial' => $this->safe(fn () => $this->aggregator->getFinancialMetrics($range)),
                'workforce' => $this->safe(fn () => $this->aggregator->getWorkforceMetrics($range)),
                'safeguarding' => $this->safe(fn () => $this->aggregator->getSafeguardingMetrics($range)),
                'decisions_required' => $this->safe(fn () => $this->aggregator->getDecisionsRequired()),
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /** @template T @param  callable():T  $fn  @return T|null */
    protected function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Meeting select options. Includes upcoming + the past 6 months so an
     * overdue CEO report can still be filed retrospectively.
     *
     * @return array<int, array{id:int,title:string,scheduled_at:string|null}>
     */
    protected function meetingOptions(?CeoBoardReport $includeReportMeeting = null): array
    {
        $cutoff = now()->subMonths(6);

        $meetings = GovernanceMeeting::query()
            ->where('scheduled_at', '>=', $cutoff)
            ->orderByDesc('scheduled_at')
            ->get(['id', 'title', 'scheduled_at']);

        if ($includeReportMeeting?->meeting && ! $meetings->contains('id', $includeReportMeeting->meeting->id)) {
            $meetings->prepend($includeReportMeeting->meeting->only(['id', 'title', 'scheduled_at']));
        }

        return $meetings->map(fn ($meeting) => [
            'id' => (int) $meeting->id,
            'title' => (string) $meeting->title,
            'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
        ])->values()->all();
    }

    protected function presentReport(CeoBoardReport $report, bool $brief = false): array
    {
        $report->loadMissing(['meeting', 'submittedBy', 'presentedBy']);

        $title = $report->meeting
            ? 'CEO Report — ' . $report->meeting->title
            : 'CEO Report #' . $report->id;

        $base = [
            'id' => $report->id,
            'title' => $title,
            'status' => $report->status,
            'period_start' => $report->period_start?->toDateString(),
            'period_end' => $report->period_end?->toDateString(),
            'period_label' => $report->periodLabel(),
            'deadline' => $report->deadline?->toIso8601String(),
            'is_overdue' => $report->isOverdue(),
            'days_until_deadline' => $report->daysUntilDeadline(),
            'meeting' => $report->meeting ? [
                'id' => $report->meeting->id,
                'title' => $report->meeting->title,
                'scheduled_at' => $report->meeting->scheduled_at?->toIso8601String(),
            ] : null,
            'author' => $report->submittedBy ? [
                'id' => $report->submittedBy->id,
                'name' => $report->submittedBy->name,
            ] : null,
            'presented_by' => $report->presentedBy ? [
                'id' => $report->presentedBy->id,
                'name' => $report->presentedBy->name,
            ] : null,
            'submitted_at' => $report->submitted_at?->toIso8601String(),
            'presented_at' => $report->presented_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];

        if ($brief) {
            return $base;
        }

        return [
            ...$base,
            'executive_summary' => $report->executive_summary,
            'operational_summary' => $report->operational_summary,
            'key_achievements' => $report->key_achievements,
            'challenges_and_risks' => $report->challenges_and_risks,
            'staffing_update' => $report->staffing_update,
            'compliance_status' => $report->compliance_status,
            'financial_summary' => $report->financial_summary,
            'recommendations' => $report->recommendations,
            'decisions_sought' => $report->decisions_sought ?? [],
            'matters_arising' => $report->matters_arising ?? [],
            'kpi_snapshot' => $report->kpi_snapshot,
            'attachments' => $this->presentAttachments($report),
            'sections_complete' => $this->sectionsComplete($report),
        ];
    }

    /**
     * Decorate stored attachment records with a download URL for the frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function presentAttachments(CeoBoardReport $report): array
    {
        $existing = is_array($report->attachments) ? $report->attachments : [];

        return collect($existing)->map(fn (array $row) => [
            'id' => $row['id'] ?? null,
            'original_name' => $row['original_name'] ?? 'attachment',
            'mime_type' => $row['mime_type'] ?? null,
            'size_bytes' => $row['size_bytes'] ?? null,
            'uploaded_at' => $row['uploaded_at'] ?? null,
            'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
            'download_url' => isset($row['id'])
                ? "/governance/ceo-reports/{$report->id}/attachments/{$row['id']}/download"
                : null,
        ])->all();
    }

    /**
     * Count of populated narrative sections.
     */
    protected function sectionsComplete(CeoBoardReport $report): int
    {
        $sections = [
            'executive_summary', 'operational_summary', 'key_achievements',
            'challenges_and_risks', 'staffing_update', 'compliance_status',
            'financial_summary', 'recommendations',
        ];

        return collect($sections)
            ->filter(fn (string $field) => ! blank($report->{$field}))
            ->count();
    }
}
