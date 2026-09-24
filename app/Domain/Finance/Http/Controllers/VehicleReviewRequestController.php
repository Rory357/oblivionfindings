<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Fleet\VehicleDocumentService;
use App\Services\Fleet\VehicleFinanceReviewQueue;
use App\Services\Fleet\VehicleFinanceService;
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

        return Inertia::render('finance/vehicle-reviews/index',
            $this->queue->present($viewer, $request->only(['status', 'search']), $focus));
    }

    public function decide(Request $request, int $reviewRequest): RedirectResponse
    {
        $viewer = $this->viewer($request);
        $record = $this->queue->scoped($viewer)->whereKey($reviewRequest)->first() ?? abort(404);
        try {
            $this->finance->decide($viewer, (int) $record->asset_id, (int) $record->id,
                (string) $request->input('decision', ''), (string) $request->input('note', ''),
                (int) $request->input('expected_version'),
                (string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''));
        } catch (HttpException $exception) {
            // A stale or already-decided request is explained in the dialog.
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return back()->withErrors(['decision' => $exception->getMessage()
                ?: 'This request changed while you were deciding. Reload before saving.']);
        }

        return back()->with('success', 'Decision recorded. The requester sees it on the vehicle.');
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
