<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrInterviewScorecard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ScorecardController extends Controller
{
    /**
     * Show scorecard form for an interview.
     */
    public function create(Request $request, HrInterview $interview)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $interview->load('application.candidate');

        $existing = HrInterviewScorecard::where('interview_id', $interview->id)
            ->where('interviewer_user_id', $user->id)
            ->first();

        return Inertia::render('hr/recruitment/scorecard', [
            'interview' => $interview,
            'existing' => $existing,
        ]);
    }

    /**
     * Store a scorecard for an interview.
     */
    public function store(Request $request, HrInterview $interview)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.manage'), 403);

        $validated = $request->validate([
            'criteria' => 'required|array|min:1',
            'criteria.*.name' => 'required|string|max:255',
            'criteria.*.rating' => 'required|integer|min:1|max:5',
            'criteria.*.notes' => 'nullable|string|max:1000',
            'overall_rating' => 'nullable|integer|min:1|max:5',
            'recommendation' => 'required|string|in:strong_yes,yes,neutral,no,strong_no',
            'strengths' => 'nullable|string|max:5000',
            'concerns' => 'nullable|string|max:5000',
            'overall_notes' => 'nullable|string|max:5000',
        ]);

        $scorecard = HrInterviewScorecard::updateOrCreate(
            [
                'interview_id' => $interview->id,
                'interviewer_user_id' => $user->id,
            ],
            array_merge($validated, [
                'tenant_id' => $interview->application?->tenant_id,
            ])
        );

        return redirect()->route('hr.candidates.show', $interview->application?->candidate_id)
            ->with('success', 'Scorecard saved successfully.');
    }

    /**
     * Show aggregated scorecards for an application.
     */
    public function summary(Request $request, HrApplication $application)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.recruitment.view'), 403);

        $application->load('candidate', 'interviews');

        $scorecards = HrInterviewScorecard::whereIn(
            'interview_id',
            $application->interviews->pluck('id')
        )->with('interviewer', 'interview')->get();

        // Aggregate scores
        $criteriaAverages = [];
        $recommendationCounts = [];

        foreach ($scorecards as $sc) {
            foreach ($sc->criteria as $criterion) {
                $name = $criterion['name'];
                if (!isset($criteriaAverages[$name])) {
                    $criteriaAverages[$name] = ['total' => 0, 'count' => 0];
                }
                $criteriaAverages[$name]['total'] += $criterion['rating'];
                $criteriaAverages[$name]['count']++;
            }

            $rec = $sc->recommendation;
            $recommendationCounts[$rec] = ($recommendationCounts[$rec] ?? 0) + 1;
        }

        $averages = [];
        foreach ($criteriaAverages as $name => $data) {
            $averages[] = [
                'name' => $name,
                'average' => round($data['total'] / $data['count'], 1),
                'count' => $data['count'],
            ];
        }

        return Inertia::render('hr/recruitment/scorecard-summary', [
            'application' => $application,
            'scorecards' => $scorecards,
            'criteriaAverages' => $averages,
            'recommendationCounts' => $recommendationCounts,
        ]);
    }
}
