<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrFeedbackTemplate;
use App\Domain\Hr\Services\FeedbackService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FeedbackController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly FeedbackService $feedbackService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — list feedback requests                                     */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $canManage = $user->canDo('hr.performance.manage');

        $allRequests = HrFeedbackRequest::forTenant($tenantId)
            ->when(! $canManage, fn ($q) => $q->where('reviewer_user_id', $user->id))
            ->with(['subject:id,name', 'requester:id,name', 'reviewer:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $allRequests->through(fn ($req) => [
            'id' => $req->id,
            'subject' => $req->subject ? ['id' => $req->subject->id, 'name' => $req->subject->name] : null,
            'requester' => $req->requester ? ['id' => $req->requester->id, 'name' => $req->requester->name] : null,
            'reviewer' => $req->reviewer ? ['id' => $req->reviewer->id, 'name' => $req->reviewer->name] : null,
            'review_type' => $req->review_type,
            'status' => $req->status,
            'due_date' => $req->due_date?->toDateString(),
            'completed_at' => $req->completed_at?->toDateString(),
            'created_at' => $req->created_at?->toDateString(),
        ]);

        $pendingCount = HrFeedbackRequest::where('reviewer_user_id', $user->id)
            ->pending()
            ->count();

        return Inertia::render('hr/feedback/index', [
            'requests' => $allRequests,
            'pendingCount' => $pendingCount,
            'can' => ['manage' => $canManage],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Request — form to initiate 360 feedback                            */
    /* ------------------------------------------------------------------ */

    public function request(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $employees = User::select('id', 'name')->orderBy('name')->get();

        $templates = HrFeedbackTemplate::forTenant($tenantId)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'questions' => $t->questions,
                'is_default' => $t->is_default,
            ]);

        return Inertia::render('hr/feedback/request', [
            'employees' => $employees,
            'reviewTypes' => FeedbackService::REVIEW_TYPES,
            'templates' => $templates,
            'defaultQuestions' => FeedbackService::FEEDBACK_QUESTIONS,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store Request — create feedback requests                           */
    /* ------------------------------------------------------------------ */

    public function storeRequest(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $validated = $request->validate([
            'subject_user_id' => ['required', 'integer', 'exists:users,id'],
            'reviewer_user_ids' => ['required', 'array', 'min:1'],
            'reviewer_user_ids.*' => ['integer', 'exists:users,id'],
            'review_type' => ['required', 'string', Rule::in(FeedbackService::REVIEW_TYPES)],
            'performance_review_id' => ['nullable', 'integer', 'exists:hr_performance_reviews,id'],
            'template_id' => ['nullable', 'integer', 'exists:hr_feedback_templates,id'],
        ]);

        try {
            $this->feedbackService->request360Feedback(
                $validated['subject_user_id'],
                $validated['reviewer_user_ids'],
                $validated['review_type'],
                $validated['performance_review_id'] ?? null,
                $user,
                $validated['template_id'] ?? null,
            );
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect('/hr/feedback')->with('success', '360-degree feedback requests sent.');
    }

    /* ------------------------------------------------------------------ */
    /*  Respond — show feedback form                                       */
    /* ------------------------------------------------------------------ */

    public function respond(Request $request, HrFeedbackRequest $feedbackRequest)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($feedbackRequest->reviewer_user_id === $user->id, 403);
        abort_unless($feedbackRequest->status === 'pending', 404);

        $feedbackRequest->load('subject:id,name');

        // Use questions from the snapshot, or fall back to defaults
        $questions = $feedbackRequest->getQuestionsMap();

        return Inertia::render('hr/feedback/respond', [
            'feedbackRequest' => [
                'id' => $feedbackRequest->id,
                'subject' => $feedbackRequest->subject ? [
                    'id' => $feedbackRequest->subject->id,
                    'name' => $feedbackRequest->subject->name,
                ] : null,
                'review_type' => $feedbackRequest->review_type,
                'due_date' => $feedbackRequest->due_date?->toDateString(),
            ],
            'questions' => $questions,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Submit Response — save feedback responses                          */
    /* ------------------------------------------------------------------ */

    public function submitResponse(Request $request, HrFeedbackRequest $feedbackRequest)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($feedbackRequest->reviewer_user_id === $user->id, 403);
        abort_unless($feedbackRequest->status === 'pending', 404);

        // Accept any question key from the snapshot (not just hardcoded ones)
        $validKeys = array_keys($feedbackRequest->getQuestionsMap());

        $validated = $request->validate([
            'responses' => ['required', 'array'],
            'responses.*.question_key' => ['required', 'string', Rule::in($validKeys)],
            'responses.*.rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.*.comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $responses = collect($validated['responses'])->keyBy('question_key')->all();

        try {
            $this->feedbackService->submitFeedback($feedbackRequest, $responses);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect('/hr/feedback')->with('success', 'Feedback submitted. Thank you!');
    }

    /* ------------------------------------------------------------------ */
    /*  Summary — aggregated feedback view                                 */
    /* ------------------------------------------------------------------ */

    public function summary(Request $request, int $user)
    {
        $currentUser = $request->user();
        abort_unless($currentUser && $currentUser->canDo('hr.performance.view'), 403);

        $subjectUser = User::findOrFail($user);
        $summary = $this->feedbackService->getFeedbackSummary($user);

        // Build questions map from summary data (dynamic, not hardcoded)
        $questions = collect($summary['questions'] ?? [])->mapWithKeys(fn ($q, $key) => [$key => $q['question']])->all();
        if (empty($questions)) {
            $questions = FeedbackService::FEEDBACK_QUESTIONS;
        }

        return Inertia::render('hr/feedback/summary', [
            'subjectUser' => ['id' => $subjectUser->id, 'name' => $subjectUser->name],
            'summary' => $summary,
            'questions' => $questions,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Template CRUD                                                       */
    /* ------------------------------------------------------------------ */

    public function storeTemplate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.key' => ['required', 'string', 'max:100'],
            'questions.*.question' => ['required', 'string', 'max:500'],
        ]);

        HrFeedbackTemplate::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'questions' => $validated['questions'],
            'is_default' => false,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Template created.');
    }

    public function updateTemplate(Request $request, HrFeedbackTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.key' => ['required', 'string', 'max:100'],
            'questions.*.question' => ['required', 'string', 'max:500'],
        ]);

        $template->update($validated);

        return redirect()->back()->with('success', 'Template updated.');
    }

    public function deleteTemplate(Request $request, HrFeedbackTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        abort_if($template->is_default, 422, 'Cannot delete the default template.');

        $template->delete();

        return redirect()->back()->with('success', 'Template deleted.');
    }
}
