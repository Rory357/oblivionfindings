<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItMajorIncidentService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\It\StoreItMajorIncidentRequest;
use App\Http\Requests\It\StoreItMajorIncidentUpdateRequest;
use App\Http\Requests\It\TransitionItMajorIncidentRequest;
use App\Http\Requests\It\UpdateItMajorIncidentRequest;
use App\Models\ControlRoomAlert;
use App\Models\ItMajorIncident;
use App\Models\ItMajorIncidentUpdate;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Site;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class ItMajorIncidentController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly ItMajorIncidentService $majorIncidentService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ItMajorIncident::class);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $period = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $filters = [
            'severity' => trim((string) $request->query('severity', '')),
            'state' => trim((string) $request->query('state', '')),
            'q' => trim((string) $request->query('q', '')),
            'from' => (string) ($period['from'] ?? ''),
            'to' => (string) ($period['to'] ?? ''),
        ];

        $majorIncidents = ItMajorIncident::query()
            ->forTenant($tenantId)
            ->with([
                'ticket:id,tenant_id,reference,title,priority,status,workflow_state,next_action,updated_at',
                'commander:id,name',
                'communicationsLead:id,name',
            ])
            ->when($filters['severity'] !== '', fn ($query) => $query->where('severity', $filters['severity']))
            ->when($filters['state'] !== '', fn ($query) => $query->whereHas('ticket', fn ($ticket) => $ticket->where('workflow_state', $filters['state'])))
            ->when($filters['from'] !== '', fn ($query) => $query->whereDate('declared_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($query) => $query->whereDate('declared_at', '<=', $filters['to']))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
                $query->where(fn ($nested) => $nested
                    ->where('impact_summary', 'like', $like)
                    ->orWhereHas('ticket', fn ($ticket) => $ticket
                        ->where('reference', 'like', $like)
                        ->orWhere('title', 'like', $like)));
            })
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ItMajorIncident $majorIncident) => $this->majorIncidentRow($majorIncident));

        return Inertia::render('it/major-incidents/index', [
            'majorIncidents' => $majorIncidents,
            'filters' => array_map(fn (string $value) => $value !== '' ? $value : null, $filters),
            'options' => ['agents' => $this->agentOptions($tenantId)],
            'can' => ['manage' => $request->user()->canDo('it.manage')],
        ]);
    }

    public function store(StoreItMajorIncidentRequest $request)
    {
        $this->authorize('create', ItMajorIncident::class);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());

        try {
            $majorIncident = $this->majorIncidentService->create($request->user(), $tenantId, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->route('it.major-incidents.show', $majorIncident)
            ->with('success', "Major incident {$majorIncident->ticket->reference} declared.");
    }

    public function show(Request $request, ItMajorIncident $majorIncident)
    {
        $this->authorize('view', $majorIncident);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $majorIncident->tenant_id);
        $majorIncident->load([
            'ticket', 'commander:id,name', 'communicationsLead:id,name',
            'updates.author:id,name',
        ]);
        $majorIncident->ticket->loadCount(['comments', 'tasks', 'approvals', 'attachments', 'events']);
        $links = $majorIncident->ticket->links()->with('linkable')->get();

        return Inertia::render('it/major-incidents/show', [
            'majorIncident' => [
                'id' => $majorIncident->id,
                'severity' => $majorIncident->severity,
                'impact_summary' => $majorIncident->impact_summary,
                'commander' => $this->userOption($majorIncident->commander),
                'communications_lead' => $this->userOption($majorIncident->communicationsLead),
                'target_update_minutes' => $majorIncident->target_update_minutes,
                'declared_at' => $majorIncident->declared_at?->toIso8601String(),
                'next_update_due_at' => $majorIncident->next_update_due_at?->toIso8601String(),
                'update_state' => $majorIncident->updateState(),
                'restoration_summary' => $majorIncident->restoration_summary,
                'restored_at' => $majorIncident->restored_at?->toIso8601String(),
                'root_cause_summary' => $majorIncident->root_cause_summary,
                'review_summary' => $majorIncident->review_summary,
                'reviewed_at' => $majorIncident->reviewed_at?->toIso8601String(),
            ],
            'ticket' => [
                ...$this->ticketOption($majorIncident->ticket),
                'description' => $majorIncident->ticket->description,
                'category' => $majorIncident->ticket->category,
                'next_action' => $majorIncident->ticket->next_action,
                'sla_state' => $majorIncident->ticket->sla_state,
                'resolution_summary' => $majorIncident->ticket->resolution_summary,
                'comments_count' => $majorIncident->ticket->comments_count,
                'tasks_count' => $majorIncident->ticket->tasks_count,
                'approvals_count' => $majorIncident->ticket->approvals_count,
                'attachments_count' => $majorIncident->ticket->attachments_count,
                'events_count' => $majorIncident->ticket->events_count,
            ],
            'updates' => $majorIncident->updates->map(fn (ItMajorIncidentUpdate $update) => [
                'id' => $update->id,
                'update_kind' => $update->update_kind,
                'audience' => $update->audience,
                'summary' => $update->summary,
                'service_status' => $update->service_status,
                'published_at' => $update->published_at?->toIso8601String(),
                'author' => $this->userOption($update->author),
            ])->values()->all(),
            'links' => $this->presentLinks($links),
            'options' => $this->options($tenantId, $majorIncident->ticket_id),
            'can' => ['manage' => $request->user()->canDo('it.manage')],
        ]);
    }

    public function update(UpdateItMajorIncidentRequest $request, ItMajorIncident $majorIncident)
    {
        $this->authorize('update', $majorIncident);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $majorIncident->tenant_id);

        try {
            $this->majorIncidentService->update($majorIncident, $request->user(), $tenantId, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Major incident updated.');
    }

    public function storeUpdate(StoreItMajorIncidentUpdateRequest $request, ItMajorIncident $majorIncident)
    {
        $this->authorize('update', $majorIncident);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $majorIncident->tenant_id);

        try {
            $this->majorIncidentService->postUpdate($majorIncident, $request->user(), $tenantId, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Major incident update published.');
    }

    public function transition(TransitionItMajorIncidentRequest $request, ItMajorIncident $majorIncident)
    {
        $this->authorize('update', $majorIncident);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $majorIncident->tenant_id);
        $data = $request->validated();

        try {
            $this->majorIncidentService->transition(
                $majorIncident,
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

        return redirect()->back()->with('success', 'Major incident state updated.');
    }

    public function status(Request $request, ItMajorIncident $majorIncident)
    {
        $this->authorize('viewStatus', $majorIncident);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $majorIncident->tenant_id);
        $majorIncident->load('ticket');

        if (! $request->user()->canDo('it.view') && ! $this->isAffectedRequester($majorIncident->ticket, $request->user())) {
            abort(404);
        }

        return response()->json([
            'reference' => $majorIncident->ticket->reference,
            'title' => $majorIncident->ticket->title,
            'severity' => $majorIncident->severity,
            'workflow_state' => $majorIncident->ticket->workflow_state,
            'impact_summary' => $majorIncident->impact_summary,
            'restored_at' => $majorIncident->restored_at?->toIso8601String(),
            'updates' => $majorIncident->updates()
                ->whereIn('audience', ['staff', 'public'])
                ->reorder()
                ->oldest('published_at')
                ->oldest('id')
                ->get()
                ->map(fn (ItMajorIncidentUpdate $update) => [
                    'id' => $update->id,
                    'update_kind' => $update->update_kind,
                    'audience' => $update->audience,
                    'summary' => $update->summary,
                    'service_status' => $update->service_status,
                    'published_at' => $update->published_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function majorIncidentRow(ItMajorIncident $majorIncident): array
    {
        return [
            ...$this->ticketOption($majorIncident->ticket),
            'major_incident_id' => $majorIncident->id,
            'severity' => $majorIncident->severity,
            'impact_summary' => $majorIncident->impact_summary,
            'commander' => $this->userOption($majorIncident->commander),
            'communications_lead' => $this->userOption($majorIncident->communicationsLead),
            'next_update_due_at' => $majorIncident->next_update_due_at?->toIso8601String(),
            'update_state' => $majorIncident->updateState(),
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>|array<string, mixed>|null> */
    private function presentLinks(Collection $links): array
    {
        $values = ['services' => [], 'sites' => [], 'incidents' => [], 'alert' => null];
        foreach ($links as $link) {
            if (! $link instanceof ItTicketLink || ! $link->linkable instanceof Model) {
                continue;
            }
            $target = $link->linkable;
            if ($link->relationship === 'affected_service' && $target instanceof ItService) {
                $values['services'][] = ['id' => $target->id, 'name' => $target->name, 'status' => $target->status];
            } elseif ($link->relationship === 'affected_site' && $target instanceof Site) {
                $values['sites'][] = ['id' => $target->id, 'name' => $target->name, 'city' => $target->city];
            } elseif ($link->relationship === 'related_incident' && $target instanceof ItTicket) {
                $values['incidents'][] = $this->ticketOption($target);
            } elseif ($link->relationship === 'source_alert' && $target instanceof ControlRoomAlert) {
                $values['alert'] = ['id' => $target->id, 'reference' => $target->reference_number, 'title' => $target->alert_type];
            }
        }

        return $values;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function options(int $tenantId, int $majorIncidentTicketId): array
    {
        return [
            'agents' => $this->agentOptions($tenantId),
            'services' => ItService::query()->forTenant($tenantId)->where('is_active', true)->orderBy('name')->limit(100)->get()
                ->map(fn (ItService $service) => ['id' => $service->id, 'name' => $service->name])->all(),
            'sites' => Site::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('name')->limit(100)->get()
                ->map(fn (Site $site) => ['id' => $site->id, 'name' => $site->name])->all(),
            'incidents' => ItTicket::query()->forTenant($tenantId)->where('work_type', 'incident')->whereKeyNot($majorIncidentTicketId)->latest('id')->limit(100)->get()
                ->map(fn (ItTicket $ticket) => $this->ticketOption($ticket))->all(),
            'alerts' => ControlRoomAlert::query()
                ->where(fn ($query) => $query
                    ->whereHas('site', fn ($site) => $site->where('tenant_id', $tenantId))
                    ->orWhereHas('device.canonicalDevice', fn ($device) => $device->where('tenant_id', $tenantId)))
                ->latest('id')->limit(100)->get()
                ->map(fn (ControlRoomAlert $alert) => ['id' => $alert->id, 'name' => ($alert->reference_number ?: 'Alert '.$alert->id).' · '.$alert->alert_type])->all(),
        ];
    }

    /** @return array<int, array{id: int, name: string}> */
    private function agentOptions(int $tenantId): array
    {
        return ItStaffDirectory::agents($tenantId)
            ->sortBy('name')
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
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

    /** @return array{id: int, name: string}|null */
    private function userOption(?Model $user): ?array
    {
        return $user ? ['id' => (int) $user->getKey(), 'name' => (string) $user->getAttribute('name')] : null;
    }

    private function isAffectedRequester(ItTicket $majorIncidentTicket, User $user): bool
    {
        return $majorIncidentTicket->links()
            ->where('relationship', 'related_incident')
            ->where('linkable_type', (new ItTicket)->getMorphClass())
            ->whereHasMorph('linkable', [ItTicket::class], fn ($query) => $query
                ->where('requester_user_id', $user->id)
                ->where('tenant_id', $majorIncidentTicket->tenant_id))
            ->exists();
    }
}
