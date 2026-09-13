<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Presenters\ItTicketApprovalPresenter;
use App\Domain\It\Services\ItTicketApprovalCandidateService;
use App\Domain\It\Services\ItTicketApprovalCommandService;
use App\Domain\It\Services\ItTicketApprovalService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\DecideApprovalRequest;
use App\Http\Requests\It\ItTicketApprovalCommandIdentityRequest;
use App\Http\Requests\It\RequestApprovalRequest;
use App\Http\Requests\It\ValidateItApprovalCandidateRequest;
use App\Http\Requests\It\WithdrawApprovalRequest;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ItTicketApprovalController extends Controller
{
    public function __construct(private readonly ItTicketApprovalService $approvals, private readonly ItTicketApprovalCommandService $commands) {}

    public function request(RequestApprovalRequest $request, ItTicket $ticket)
    {
        return $this->respond($request, function () use ($request, $ticket) {
            return $request->has('request_uuid')
                ? $this->commands->execute($ticket, $request->user(), 'request', $request->validated())
                : $this->approvals->request($ticket, $request->user(), $request->validated('reason'), $request->validated());
        }, created: true);
    }

    public function validateCandidate(ValidateItApprovalCandidateRequest $request, ItTicket $ticket, ItTicketApprovalCandidateService $candidates)
    {
        return response()->json($candidates->validate($ticket, $request->user(), $request->validated()), 200,
            ['Cache-Control' => 'no-store, private']);
    }

    public function history(Request $request, ItTicket $ticket, ItTicketApprovalPresenter $presenter)
    {
        $input = $request->validate(['actor_user_id' => ['required', 'integer', 'min:1'], 'review_nonce' => ['required', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'expected_version' => ['sometimes', 'integer', 'min:1'],
            'approval_id' => ['sometimes', 'integer', 'min:1']]);
        if (isset($input['approval_id']) && ($input['page'] ?? 1) > 1) {
            throw ValidationException::withMessages(['page' => 'Choose a linked request or a later page, not both.']);
        }
        if (($input['page'] ?? 1) > 1 && ! isset($input['expected_version'])) {
            throw ValidationException::withMessages(['expected_version' => 'Keep the reviewed ticket version with later history pages.']);
        }

        return response()->json($presenter->history($ticket, $request->user(), $input), 200, ['Cache-Control' => 'no-store, private']);
    }

    public function decide(DecideApprovalRequest $request, ItTicketApproval $approval)
    {
        return $this->respond($request, function () use ($request, $approval) {
            return $request->has('request_uuid')
                ? $this->commands->execute($approval->ticket, $request->user(), 'decide', $request->validated(), $approval)
                : $this->approvals->decide($approval, $request->user(), $request->validated('decision'), $request->validated('reason'));
        });
    }

    public function decideNested(DecideApprovalRequest $request, ItTicket $ticket, ItTicketApproval $approval)
    {
        abort_unless((int) $approval->it_ticket_id === (int) $ticket->id, 404);

        return $this->decide($request, $approval);
    }

    public function recover(ItTicketApprovalCommandIdentityRequest $request, ItTicket $ticket, string $operation, string $requestUuid)
    {
        return $this->respond($request, fn () => $this->commands->recover($ticket, $request->user(), $operation, $requestUuid,
            $request->filled('approval_id') ? $request->integer('approval_id') : null));
    }

    public function withdraw(WithdrawApprovalRequest $request, ItTicket $ticket, ItTicketApproval $approval)
    {
        abort_unless((int) $approval->it_ticket_id === (int) $ticket->id, 404);

        return $this->respond($request, fn () => $request->has('request_uuid')
            ? $this->commands->execute($ticket, $request->user(), 'withdraw', $request->validated(), $approval)
            : $this->approvals->withdraw($approval, $request->user(), $request->validated('reason')));
    }

    public function cancel(ItTicketApprovalCommandIdentityRequest $request, ItTicket $ticket, string $operation, string $requestUuid)
    {
        return $this->respond($request, fn () => $this->commands->cancel($ticket, $request->user(), $operation, $requestUuid,
            $request->integer('actor_user_id'), $request->filled('approval_id') ? $request->integer('approval_id') : null));
    }

    private function respond(Request $request, \Closure $action, bool $created = false)
    {
        try {
            $result = $action();
        } catch (ItTicketVersionConflict $conflict) {
            $response = $conflict->render($request);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        } catch (AuthorizationException|ModelNotFoundException|ValidationException $expected) {
            throw $expected;
        } catch (DomainException $exception) {
            if (! $request->expectsJson()) {
                return redirect()->back()->withErrors(['form' => $exception->getMessage()])->with('error', $exception->getMessage());
            }

            return response()->json(['code' => $exception instanceof ItTicketCommandConflict ? 'idempotency_conflict' : 'approval_validation_failed',
                'message' => $exception->getMessage(), 'errors' => ['form' => [$exception->getMessage()]]],
                $exception instanceof ItTicketCommandConflict ? 409 : 422, ['Cache-Control' => 'no-store, private']);
        } catch (Throwable $exception) {
            Log::error('IT approval outcome could not be confirmed.', ['exception_type' => $exception::class]);
            $message = 'The approval result could not be confirmed. Keep your proposal and check the saved request before retrying.';
            if (! $request->expectsJson()) {
                return redirect()->back()->withErrors(['form' => $message])->with('error', $message);
            }

            return response()->json(['code' => 'approval_outcome_unknown', 'message' => $message], 500, ['Cache-Control' => 'no-store, private']);
        }
        $legacy = $result instanceof ItTicketApproval;
        if ($legacy || $result->status === 'committed') {
            try {
                DispatchItTicketNotifications::dispatchAfterResponse($legacy ? (int) $result->it_ticket_id : $result->data['id']);
            } catch (Throwable) {
                // The outbox is committed. Its scheduled drain can retry dispatch.
            }
        }
        if (! $request->expectsJson()) {
            if (! $legacy && $result->status === 'cancelled') {
                return redirect()->back()->withErrors(['form' => 'This command was cancelled. Start a new approval proposal when ready.']);
            }
            $status = $legacy ? $result->status : $result->data['approval_status'];

            return redirect()->back()->with('success', $status === 'pending' ? 'Approval requested.' : 'Approval '.$status.'.');
        }

        return response()->json($result->toArray(), $created && $result->status === 'committed' && ! $result->data['replayed'] ? 201 : 200,
            ['Cache-Control' => 'no-store, private']);
    }
}
