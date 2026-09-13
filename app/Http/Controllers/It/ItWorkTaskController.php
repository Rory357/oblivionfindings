<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\It\Services\ItWorkTaskCandidateService;
use App\Domain\It\Services\ItWorkTaskCommandService;
use App\Domain\It\Services\ItWorkTaskService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\CompleteItWorkTaskRequest;
use App\Http\Requests\It\ItWorkTaskCommandIdentityRequest;
use App\Http\Requests\It\ReopenItWorkTaskRequest;
use App\Http\Requests\It\ReorderItWorkTasksRequest;
use App\Http\Requests\It\StoreItWorkTaskRequest;
use App\Http\Requests\It\UpdateItWorkTaskRequest;
use App\Http\Requests\It\ValidateItWorkTaskCandidateRequest;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use DomainException;
use Illuminate\Http\Request;

class ItWorkTaskController extends Controller
{
    public function __construct(
        private readonly ItWorkTaskService $taskService,
        private readonly ItWorkAccessService $workAccess,
        private readonly ItWorkTaskCommandService $commands,
    ) {}

    public function store(StoreItWorkTaskRequest $request, ItTicket $ticket)
    {
        $this->workContext($request, $ticket);
        if ($request->has('request_uuid')) {
            return $this->commandResponse($request, fn () => $this->commands->execute($ticket, $request->user(), 'create', $request->validated()), created: true);
        }

        try {
            $task = $this->taskService->create($ticket, $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return $this->failure($request, $exception);
        }

        return redirect()->back()->with('success', "Task added — {$task->title}.");
    }

    public function update(UpdateItWorkTaskRequest $request, ItTicket $ticket, ItWorkTask $task)
    {
        $this->workContext($request, $ticket, $task);
        if ($request->has('request_uuid')) {
            return $this->commandResponse($request, fn () => $this->commands->execute($ticket, $request->user(), 'update', $request->validated(), $task));
        }

        try {
            $this->taskService->update($ticket, $task, $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return $this->failure($request, $exception);
        }

        return redirect()->back()->with('success', 'Task updated.');
    }

    public function complete(CompleteItWorkTaskRequest $request, ItTicket $ticket, ItWorkTask $task)
    {
        $this->workContext($request, $ticket, $task);
        if ($request->has('request_uuid')) {
            return $this->commandResponse($request, fn () => $this->commands->execute($ticket, $request->user(), 'complete', $request->validated(), $task));
        }

        try {
            $this->taskService->complete($ticket, $task, $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return $this->failure($request, $exception);
        }

        return redirect()->back()->with('success', 'Task completed.');
    }

    public function reopen(ReopenItWorkTaskRequest $request, ItTicket $ticket, ItWorkTask $task)
    {
        $this->workContext($request, $ticket, $task);
        if ($request->has('request_uuid')) {
            return $this->commandResponse($request, fn () => $this->commands->execute($ticket, $request->user(), 'reopen', $request->validated(), $task));
        }

        try {
            $this->taskService->reopen(
                $ticket,
                $task,
                $request->user(),
                (string) $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            return $this->failure($request, $exception);
        }

        return redirect()->back()->with('success', 'Task reopened.');
    }

    public function reorder(ReorderItWorkTasksRequest $request, ItTicket $ticket)
    {
        return $this->commandResponse($request, fn () => $this->commands->execute($ticket, $request->user(), 'reorder', $request->validated()));
    }

    public function validateCandidate(ValidateItWorkTaskCandidateRequest $request, ItTicket $ticket)
    {
        return response()->json(app(ItWorkTaskCandidateService::class)->validate(
            $ticket, $request->user(), $request->validated(),
        ))->header('Cache-Control', 'no-store, private');
    }

    public function recover(ItWorkTaskCommandIdentityRequest $request, ItTicket $ticket, string $operation, string $requestUuid)
    {
        return $this->commandResponse($request, fn () => $this->commands->recover(
            $ticket, $request->user(), $operation, $requestUuid, $request->filled('task_id') ? $request->integer('task_id') : null,
        ));
    }

    public function cancel(ItWorkTaskCommandIdentityRequest $request, ItTicket $ticket, string $operation, string $requestUuid)
    {
        return $this->commandResponse($request, fn () => $this->commands->cancel(
            $ticket, $request->user(), $operation, $requestUuid, $request->integer('actor_user_id'),
            $request->filled('task_id') ? $request->integer('task_id') : null,
        ));
    }

    private function commandResponse(Request $request, \Closure $command, bool $created = false)
    {
        try {
            $result = $command();
        } catch (DomainException $exception) {
            return $this->failure($request, $exception);
        }
        if (! $request->expectsJson()) {
            if ($result->status === 'cancelled') {
                return redirect()->back()->withErrors(['form' => 'This command was cancelled. Start a new task change when ready.']);
            }

            return redirect()->back()->with('success', $result->data['changed'] ? 'Task change saved.' : 'Task already matches these changes.');
        }

        return response()->json($result->toArray(), $created && $result->status === 'committed'
            && ! $result->data['replayed'] && $result->data['changed'] ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }

    private function failure(Request $request, DomainException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['code' => $exception instanceof ItTicketCommandConflict ? 'idempotency_conflict' : 'task_validation_failed',
                'message' => $exception->getMessage(), 'errors' => ['form' => [$exception->getMessage()]]],
                $exception instanceof ItTicketCommandConflict ? 409 : 422)->header('Cache-Control', 'no-store, private');
        }

        return redirect()->back()->withErrors(['form' => $exception->getMessage()])->with('error', $exception->getMessage());
    }

    private function workContext($request, ItTicket $ticket, ?ItWorkTask $task = null): void
    {
        abort_unless($this->workAccess->canWork($request->user(), $ticket), 404);
        if ($task !== null) {
            abort_unless((int) $task->ticket_id === (int) $ticket->id, 404);
        }

    }
}
