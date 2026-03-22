<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrSurvey;
use App\Domain\Hr\Services\SurveyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

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
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.surveys.view'), 403);

        $tenantId = null;
        $status = $request->query('status');

        $surveys = HrSurvey::forTenant($tenantId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->withCount('responses')
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $surveys->through(fn ($survey) => [
            'id' => $survey->id,
            'title' => $survey->title,
            'survey_type' => $survey->survey_type,
            'status' => $survey->status,
            'is_anonymous' => $survey->is_anonymous,
            'starts_at' => $survey->starts_at?->toDateString(),
            'ends_at' => $survey->ends_at?->toDateString(),
            'responses_count' => $survey->responses_count,
            'created_by' => $survey->creator?->name,
            'created_at' => $survey->created_at?->toDateString(),
        ]);

        return Inertia::render('hr/surveys/index', [
            'surveys' => $surveys,
            'filters' => [
                'status' => $status,
            ],
            'can' => [
                'create' => $user->canDo('hr.surveys.manage'),
                'manage' => $user->canDo('hr.surveys.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create — form builder for new survey                               */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.surveys.manage'), 403);

        return Inertia::render('hr/surveys/create', [
            'surveyTypes' => SurveyService::SURVEY_TYPES,
            'questionTypes' => SurveyService::QUESTION_TYPES,
        ]);
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
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.surveys.view'), 403);

        $results = $this->surveyService->calculateResults($survey);
        $enps = $survey->survey_type === 'enps'
            ? $this->surveyService->getENPSScore($survey)
            : null;

        $survey->load('questions');

        return Inertia::render('hr/surveys/results', [
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'description' => $survey->description,
                'survey_type' => $survey->survey_type,
                'status' => $survey->status,
                'is_anonymous' => $survey->is_anonymous,
                'starts_at' => $survey->starts_at?->toDateString(),
                'ends_at' => $survey->ends_at?->toDateString(),
            ],
            'results' => $results,
            'enps' => $enps,
            'can' => [
                'manage' => $user->canDo('hr.surveys.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Respond — show form to fill survey                                 */
    /* ------------------------------------------------------------------ */

    public function respond(Request $request, HrSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($survey->status === 'active', 404);

        $survey->load(['questions' => fn ($q) => $q->orderBy('sort_order')]);

        return Inertia::render('hr/surveys/respond', [
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'description' => $survey->description,
                'survey_type' => $survey->survey_type,
                'is_anonymous' => $survey->is_anonymous,
                'ends_at' => $survey->ends_at?->toDateString(),
                'questions' => $survey->questions->map(fn ($q) => [
                    'id' => $q->id,
                    'question_text' => $q->question_text,
                    'question_type' => $q->question_type,
                    'options' => $q->options,
                    'is_required' => $q->is_required,
                ]),
            ],
        ]);
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
