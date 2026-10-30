<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Fleet\VehicleDocumentService;
use App\Services\Fleet\VehicleFinanceReviewQueue;
use App\Services\Fleet\VehicleFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Finance › Vehicle reviews: review requests raised from a vehicle's Finance
 * view, read and decided by Finance under Finance's own Site rule. Finance
 * needs no Fleet access; a decision changes no Finance record by itself.
 */
class VehicleReviewRequestController extends Controller
{
    public function __construct(
        private readonly VehicleFinanceReviewQueue $queue,
        private readonly VehicleFinanceService $finance,
        private readonly VehicleDocumentService $documents,
    ) {}

    public function index(Request $request): Response
    {
        $viewer = $this->viewer($request);
        abort_unless($this->queue->canView($viewer), 403);
        $focus = $request->integer('request') ?: null;
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,ready,mine,overdue,preparing,changes_requested,decided,all'],
            'sort' => ['sometimes', 'string', 'in:oldest,newest,due,amount'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return Inertia::render('finance/vehicle-reviews/index',
            $this->queue->present($viewer, $filters, $focus));
    }

    public function history(Request $request, int $reviewRequest): JsonResponse
    {
        $validated = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->queue->history(
            $this->viewer($request), $reviewRequest, isset($validated['before']) ? (int) $validated['before'] : null,
        ));
    }

    public function decide(Request $request, int $reviewRequest): RedirectResponse|JsonResponse
    {
        $request->validate(['evidence_token' => ['required', 'string', 'size:64']]);
        $viewer = $this->viewer($request);
        $record = $this->queue->scoped($viewer)->whereKey($reviewRequest)->first() ?? abort(404);
        try {
            $this->finance->decide($viewer, (int) $record->asset_id, (int) $record->id,
                (string) $request->input('decision', ''), (string) $request->input('note', ''),
                (int) $request->input('expected_version'),
                (string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''), (string) $request->input('evidence_token'));
        } catch (HttpException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }
            // A stale or already-decided request is explained in the dialog.
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return back()->withErrors(['decision' => $exception->getMessage()
                ?: 'This request changed while you were deciding. Reload before saving.']);
        }

        if ($request->expectsJson()) {
            return response()->json(['saved' => true]);
        }

        return back()->with('success', 'Decision recorded. The requester sees it on the vehicle.');
    }

    public function assign(Request $request, int $reviewRequest): JsonResponse
    {
        $data = $request->validate(['assigned_to_user_id' => ['nullable', 'integer', 'min:1'], 'due_on' => ['nullable', 'date_format:Y-m-d'], 'expected_version' => ['required', 'integer', 'min:1']]);
        $saved = $this->finance->assign($this->viewer($request), $reviewRequest, isset($data['assigned_to_user_id']) ? (int) $data['assigned_to_user_id'] : null,
            $data['due_on'] ?? null, (int) $data['expected_version'], (string) $request->header('Idempotency-Key'));

        return response()->json(['saved' => true, 'version' => $saved->lock_version]);
    }

    public function reviewers(Request $request, int $reviewRequest): JsonResponse
    {
        $viewer = $this->viewer($request);
        abort_unless($this->finance->canDecide($viewer), 403);
        $record = $this->queue->scoped($viewer)->with('asset')->findOrFail($reviewRequest);
        $values = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'after' => ['nullable', 'integer', 'min:0']]);
        $results = [];
        $after = (int) ($values['after'] ?? 0);
        $query = User::query()->whereNotNull('approved_at')->whereKeyNot($record->requested_by_user_id)->where('id', '>', $after)
            ->when(filled($values['search'] ?? null), fn ($q) => $q->where('name', 'like', '%'.addcslashes($values['search'], '%_\\').'%'));
        foreach ($query->orderBy('id')->lazyById(100) as $candidate) {
            $after = (int) $candidate->id;
            if ($this->finance->canDecide($candidate) && ($candidate->canDo('finance.assets.view') || $candidate->canDo('finance.ap.view'))
                && in_array((int) $record->asset->site_id, $this->finance->financeSiteIds($candidate), true)) {
                $results[] = ['id' => $candidate->id, 'name' => $candidate->name];
                if (count($results) === 30) {
                    break;
                }
            }
        }

        return response()->json(['reviewers' => $results, 'next_after' => count($results) === 30 ? $after : null]);
    }

    public function file(Request $request, int $reviewRequest, int $document): StreamedResponse
    {
        $viewer = $this->viewer($request);
        abort_unless($this->queue->canView($viewer), 403);
        $record = $this->queue->scoped($viewer)->whereKey($reviewRequest)->first() ?? abort(404);

        return $this->documents->financeReviewFile($record, $document, $request->boolean('inline'));
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return User::query()->findOrFail($user->id);
    }
}
