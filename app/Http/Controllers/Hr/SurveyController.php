<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrSurvey;
use App\Domain\Hr\Services\SurveyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SurveyController extends Controller
{
    public function __construct(
        private readonly SurveyService $surveyService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — list of surveys                                            */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        // RETIRED: the standalone HrSurvey system is superseded by the richer
        // Wellbeing engagement-survey system (anonymity, scoring, eNPS, action
        // plans + SLA reminders). Route preserved as a redirect for any bookmarks.
        return redirect()->route('hr.wellbeing.index');
    }

    /* ------------------------------------------------------------------ */
    /*  Create — form builder for new survey                               */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        return redirect()->route('hr.wellbeing.index');
    }

    /* ------------------------------------------------------------------ */
    /*  Store — persist new survey                                         */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.surveys.manage'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'survey_type' => ['required', 'string', Rule::in(SurveyService::SURVEY_TYPES)],
            'is_anonymous' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_text' => ['required', 'string', 'max:1000'],
            'questions.*.question_type' => ['required', 'string', Rule::in(SurveyService::QUESTION_TYPES)],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.is_required' => ['boolean'],
        ]);

        $validated['tenant_id'] = $user->tenant_id;
        $validated['created_by'] = $user->id;

        try {
            $survey = $this->surveyService->createSurvey($validated);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect("/hr/surveys/{$survey->id}")->with('success', 'Survey created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Show — survey results                                              */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrSurvey $survey)
    {
        return redirect()->route('hr.wellbeing.index');
    }

    /* ------------------------------------------------------------------ */
    /*  Respond — show form to fill survey                                 */
    /* ------------------------------------------------------------------ */

    public function respond(Request $request, HrSurvey $survey)
    {
        return redirect()->route('hr.wellbeing.index');
    }

    /* ------------------------------------------------------------------ */
    /*  Submit Response — store survey response                            */
    /* ------------------------------------------------------------------ */

    public function submitResponse(Request $request, HrSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer', 'exists:hr_survey_questions,id'],
            'answers.*.answer_text' => ['nullable', 'string', 'max:5000'],
            'answers.*.answer_rating' => ['nullable', 'integer', 'min:0', 'max:10'],
            'answers.*.answer_choice' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->surveyService->submitResponse($survey, $user, $validated['answers']);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect('/hr/surveys')->with('success', 'Survey response submitted. Thank you!');
    }
}
