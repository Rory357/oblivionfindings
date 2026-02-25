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
        $reports = CeoBoardReport::with(['meeting', 'submittedBy'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return Inertia::render('Governance/CeoReports/Index', [
            'reports' => $reports,
        ]);
    }

    public function create(Request $request)
    {
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
        $report->load(['meeting', 'submittedBy']);

        return Inertia::render('Governance/CeoReports/Show', [
            'report' => $report,
        ]);
    }

    public function update(Request $request, CeoBoardReport $report)
    {
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
        $report->submit();

        return redirect()->back()->with('success', 'CEO report submitted to board.');
    }
}
