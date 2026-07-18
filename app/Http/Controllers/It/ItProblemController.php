<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItProblemService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\It\StoreItProblemRequest;
use App\Http\Requests\It\TransitionItProblemRequest;
use App\Http\Requests\It\UpdateItProblemRequest;
use App\Models\ItProblem;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use DomainException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ItProblemController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly ItProblemService $problemService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ItProblem::class);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $state = trim((string) $request->query('state', ''));
        $search = trim((string) $request->query('q', ''));
        $period = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = (string) ($period['from'] ?? '');
        $to = (string) ($period['to'] ?? '');

        $problems = ItProblem::query()
            ->forTenant($tenantId)
            ->with('ticket:id,tenant_id,reference,title,priority,status,workflow_state,next_action,updated_at')
            ->when($state !== '', fn ($query) => $query->whereHas('ticket', fn ($ticket) => $ticket->where('workflow_state', $state)))
            ->when($from !== '', fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn ($nested) => $nested
                    ->where('root_cause', 'like', $like)
                    ->orWhere('workaround', 'like', $like)
                    ->orWhereHas('ticket', fn ($ticket) => $ticket
                        ->where('reference', 'like', $like)
                        ->orWhere('title', 'like', $like)));
            })
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ItProblem $problem) => $this->problemRow($problem));

        return Inertia::render('it/problems/index', [
            'problems' => $problems,
            'filters' => [
                'state' => $state ?: null,
                'q' => $search ?: null,
                'from' => $from ?: null,
                'to' => $to ?: null,
            ],
            'can' => ['manage' => $request->user()->canDo('it.manage')],
        ]);
    }

    public function store(StoreItProblemRequest $request)
    {
        $this->authorize('create', ItProblem::class);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());

        try {
            $problem = $this->problemService->create($request->user(), $tenantId, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->route('it.problems.show', $problem)->with('success', "Problem {$problem->ticket->reference} opened.");
    }

    public function show(Request $request, ItProblem $problem)
    {
        $this->authorize('view', $problem);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $problem->tenant_id);
        $problem->load('ticket');
        $ticket = $problem->ticket;
        $ticket->loadCount(['comments', 'tasks', 'approvals', 'attachments', 'events']);

        $incidentLinks = $ticket->links()
            ->where('relationship', 'related_incident')
            ->with('linkable')
            ->get();
        $changeLink = $ticket->links()
            ->where('relationship', 'related_change')
            ->with('linkable')
            ->first();

        return Inertia::render('it/problems/show', [
            'problem' => [
                'id' => $problem->id,
                'impact_summary' => $problem->impact_summary,
                'root_cause' => $problem->root_cause,
                'workaround' => $problem->workaround,
                'corrective_action' => $problem->corrective_action,
                'known_error_at' => $problem->known_error_at?->toIso8601String(),
            ],
            'ticket' => [
                ...$this->ticketOption($ticket),
                'description' => $ticket->description,
                'category' => $ticket->category,
                'next_action' => $ticket->next_action,
                'sla_state' => $ticket->sla_state,
                'first_response_due_at' => $ticket->first_response_due_at?->toIso8601String(),
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
                'comments_count' => $ticket->comments_count,
                'tasks_count' => $ticket->tasks_count,
                'approvals_count' => $ticket->approvals_count,
                'attachments_count' => $ticket->attachments_count,
                'events_count' => $ticket->events_count,
            ],
            'incidents' => $incidentLinks->map(fn (ItTicketLink $link) => $this->ticketOption($link->linkable))->values()->all(),
            'permanentFixChange' => $changeLink?->linkable instanceof ItTicket ? $this->ticketOption($changeLink->linkable) : null,
            'incidentOptions' => ItTicket::query()
                ->forTenant($tenantId)
                ->whereIn('work_type', ['incident', 'major_incident'])
                ->latest('id')->limit(100)->get()->map(fn (ItTicket $candidate) => $this->ticketOption($candidate))->all(),
            'changeOptions' => ItTicket::query()
                ->forTenant($tenantId)
                ->where('work_type', 'change')
                ->latest('id')->limit(100)->get()->map(fn (ItTicket $candidate) => $this->ticketOption($candidate))->all(),
            'can' => ['manage' => $request->user()->canDo('it.manage')],
        ]);
    }

    public function update(UpdateItProblemRequest $request, ItProblem $problem)
    {
        $this->authorize('update', $problem);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $problem->tenant_id);

        try {
            $this->problemService->update($problem, $request->user(), $tenantId, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Problem updated.');
    }

    public function transition(TransitionItProblemRequest $request, ItProblem $problem)
    {
        $this->authorize('update', $problem);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $problem->tenant_id);
        $data = $request->validated();

        try {
            $this->problemService->transition(
                $problem,
                $request->user(),
                $tenantId,
                ItWorkflowState::from((string) $data['workflow_state']),
                (string) $data['reason'],
                $data['resolution_code'] ?? null,
                $data['resolution_summary'] ?? null,
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Problem state updated.');
    }

    /** @return array<string, mixed> */
    private function problemRow(ItProblem $problem): array
    {
        return [
            ...$this->ticketOption($problem->ticket),
            'problem_id' => $problem->id,
            'impact_summary' => $problem->impact_summary,
            'known_error_at' => $problem->known_error_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function ticketOption(ItTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'reference' => $ticket->reference,
            'title' => $ticket->title,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'workflow_state' => $ticket->workflow_state,
            'href' => "/it/tickets/{$ticket->id}",
        ];
    }
}
