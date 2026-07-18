<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItWorkTaskService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\It\CompleteItWorkTaskRequest;
use App\Http\Requests\It\ReopenItWorkTaskRequest;
use App\Http\Requests\It\StoreItWorkTaskRequest;
use App\Http\Requests\It\UpdateItWorkTaskRequest;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use DomainException;

class ItWorkTaskController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly ItWorkTaskService $taskService,
    ) {}

    public function store(StoreItWorkTaskRequest $request, ItTicket $ticket)
    {
        $tenantId = $this->tenant($request, $ticket);

        try {
            $task = $this->taskService->create($ticket, $request->user(), $tenantId, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Task added — {$task->title}.");
    }

    public function update(UpdateItWorkTaskRequest $request, ItTicket $ticket, ItWorkTask $task)
    {
        $tenantId = $this->tenant($request, $ticket);

        try {
            $this->taskService->update($ticket, $task, $request->user(), $tenantId, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Task updated.');
    }

    public function complete(CompleteItWorkTaskRequest $request, ItTicket $ticket, ItWorkTask $task)
    {
        $tenantId = $this->tenant($request, $ticket);

        try {
            $this->taskService->complete($ticket, $task, $request->user(), $tenantId, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Task completed.');
    }

    public function reopen(ReopenItWorkTaskRequest $request, ItTicket $ticket, ItWorkTask $task)
    {
        $tenantId = $this->tenant($request, $ticket);

        try {
            $this->taskService->reopen(
                $ticket,
                $task,
                $request->user(),
                $tenantId,
                (string) $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Task reopened.');
    }

    private function tenant($request, ItTicket $ticket): int
    {
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        return $tenantId;
    }
}
