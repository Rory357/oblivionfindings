<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\It\Services\ItWorkTaskService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\CompleteItWorkTaskRequest;
use App\Http\Requests\It\ReopenItWorkTaskRequest;
use App\Http\Requests\It\StoreItWorkTaskRequest;
use App\Http\Requests\It\UpdateItWorkTaskRequest;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use DomainException;

class ItWorkTaskController extends Controller
{
    public function __construct(
        private readonly ItWorkTaskService $taskService,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    public function store(StoreItWorkTaskRequest $request, ItTicket $ticket)
    {
        $this->workContext($request, $ticket);

        try {
            $task = $this->taskService->create($ticket, $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Task added — {$task->title}.");
    }

    public function update(UpdateItWorkTaskRequest $request, ItTicket $ticket, ItWorkTask $task)
    {
        $this->workContext($request, $ticket, $task);

        try {
            $this->taskService->update($ticket, $task, $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Task updated.');
    }

    public function complete(CompleteItWorkTaskRequest $request, ItTicket $ticket, ItWorkTask $task)
    {
        $this->workContext($request, $ticket, $task);

        try {
            $this->taskService->complete($ticket, $task, $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Task completed.');
    }

    public function reopen(ReopenItWorkTaskRequest $request, ItTicket $ticket, ItWorkTask $task)
    {
        $this->workContext($request, $ticket, $task);

        try {
            $this->taskService->reopen(
                $ticket,
                $task,
                $request->user(),
                (string) $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Task reopened.');
    }

    private function workContext($request, ItTicket $ticket, ?ItWorkTask $task = null): void
    {
        abort_unless($this->workAccess->canWork($request->user(), $ticket), 404);
        if ($task !== null) {
            abort_unless((int) $task->ticket_id === (int) $ticket->id, 404);
        }

    }
}
