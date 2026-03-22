<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Services\FeedbackService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FeedbackController extends Controller
{
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

        $tenantId = $user->tenant_id;

        // HR/managers see all requests for their tenant; regular users see their pending ones
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
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Request — form to initiate 360 feedback                            */
    /* ------------------------------------------------------------------ */

    public function request(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $tenantId = $user->tenant_id;

        $employees = User::where('tenant_id', $tenantId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('hr/feedback/request', [
            'employees' => $employees,
            'reviewTypes' => FeedbackService::REVIEW_TYPES,
            'questions' => FeedbackService::FEEDBACK_QUESTIONS,
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
        ]);

        try {
            $this->feedbackService->request360Feedback(
                $validated['subject_user_id'],
                $validated['reviewer_user_ids'],
                $validated['review_type'],
                $validated['performance_review_id'] ?? null,
                $user,
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
            'questions' => FeedbackService::FEEDBACK_QUESTIONS,
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

        $questionKeys = array_keys(FeedbackService::FEEDBACK_QUESTIONS);

        $validated = $request->validate([
            'responses' => ['required', 'array'],
            'responses.*.question_key' => ['required', 'string', Rule::in($questionKeys)],
            'responses.*.rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.*.comment' => ['nullable', 'string', 'max:2000'],
        ]);

        // Transform array to keyed format
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

        return Inertia::render('hr/feedback/summary', [
            'subjectUser' => [
                'id' => $subjectUser->id,
                'name' => $subjectUser->name,
            ],
            'summary' => $summary,
            'questions' => FeedbackService::FEEDBACK_QUESTIONS,
        ]);
    }
}
