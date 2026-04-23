<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\CeoBoardReport;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CeoBoardReportController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('governance.ceo-reports.view'), 403);

        $reports = CeoBoardReport::with(['meeting', 'submittedBy'])
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(fn (CeoBoardReport $report) => $this->presentReport($report));

        return Inertia::render('Governance/CeoReports/Index', [
            'reports' => $reports,
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()?->canDo('governance.ceo-reports.manage'), 403);

        $meetings = GovernanceMeeting::query()
            ->where('scheduled_at', '>=', now())
            ->select('id', 'title', 'scheduled_at')
            ->orderBy('scheduled_at')
            ->get();

        return Inertia::render('Governance/CeoReports/Create', [
            'meetings' => $meetings,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->canDo('governance.ceo-reports.manage'), 403);

        $validated = $request->validate([
            'governance_meeting_id' => 'required|exists:governance_meetings,id',
            'operational_summary' => 'nullable|string',
            'key_achievements' => 'nullable|string',
            'challenges_and_risks' => 'nullable|string',
            'staffing_update' => 'nullable|string',
            'compliance_status' => 'nullable|string',
            'financial_summary' => 'nullable|string',
            'recommendations' => 'nullable|string',
        ]);

        $report = CeoBoardReport::create([
            ...$validated,
            'submitted_by' => auth()->id(),
            'status' => 'draft',
        ]);

        return redirect()->route('governance.ceo-reports.show', $report)
            ->with('success', 'CEO report created.');
    }

    public function show(CeoBoardReport $report)
    {
        abort_unless(request()->user()?->canDo('governance.ceo-reports.view'), 403);

        $report->load(['meeting', 'submittedBy']);

        return Inertia::render('Governance/CeoReports/Show', [
            'report' => $this->presentReport($report),
        ]);
    }

    public function update(Request $request, CeoBoardReport $report)
    {
        abort_unless($request->user()?->canDo('governance.ceo-reports.manage'), 403);

        $validated = $request->validate([
            'operational_summary' => 'nullable|string',
            'key_achievements' => 'nullable|string',
            'challenges_and_risks' => 'nullable|string',
            'staffing_update' => 'nullable|string',
            'compliance_status' => 'nullable|string',
            'financial_summary' => 'nullable|string',
            'recommendations' => 'nullable|string',
        ]);

        $report->update($validated);

        return redirect()->back()->with('success', 'CEO report updated.');
    }

    public function submit(CeoBoardReport $report)
    {
        abort_unless(request()->user()?->canDo('governance.ceo-reports.manage'), 403);

        $report->submit();

        return redirect()->back()->with('success', 'CEO report submitted to board.');
    }

    protected function presentReport(CeoBoardReport $report): array
    {
        $report->loadMissing(['meeting', 'submittedBy']);

        $title = $report->meeting
            ? 'CEO Report - ' . $report->meeting->title
            : 'CEO Report #' . $report->id;

        return [
            'id' => $report->id,
            'title' => $title,
            'status' => $report->status,
            'executive_summary' => $report->operational_summary,
            'operational_highlights' => $this->explodeParagraphs($report->key_achievements),
            'financial_summary' => $this->explodeParagraphs($report->financial_summary),
            'risk_updates' => $this->explodeParagraphs($report->challenges_and_risks),
            'compliance_updates' => $this->explodeParagraphs($report->compliance_status),
            'strategic_progress' => $this->explodeParagraphs($report->staffing_update),
            'recommendations' => $this->explodeParagraphs($report->recommendations),
            'meeting' => $report->meeting ? [
                'id' => $report->meeting->id,
                'title' => $report->meeting->title,
                'scheduled_at' => $report->meeting->scheduled_at?->toIso8601String(),
            ] : null,
            'author' => $report->submittedBy ? [
                'id' => $report->submittedBy->id,
                'name' => $report->submittedBy->name,
            ] : null,
            'submitted_at' => $report->submitted_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }

    protected function explodeParagraphs(?string $value): ?array
    {
        if (blank($value)) {
            return null;
        }

        return collect(preg_split('/\r\n|\r|\n/', trim($value)))
            ->map(fn (?string $line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();
    }
}
