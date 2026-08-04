<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItLinkedContextOptions;
use App\Domain\It\Services\ItMajorIncidentService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Controllers\Controller;
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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ItMajorIncidentController extends Controller
{
    public function __construct(
        private readonly ItMajorIncidentService $majorIncidentService,
        private readonly ItWorkAccessService $workAccess,
        private readonly ItLinkedContextOptions $linkedOptions,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ItMajorIncident::class);
        $user = $request->user();
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
            ->whereHas('ticket', fn ($ticket) => $this->workAccess->applyViewScope($ticket, $user))
            ->with([
                'ticket:id,reference,title,priority,status,workflow_state,next_action,updated_at',
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
            'options' => ['agents' => $this->linkedOptions->agents($user)],
            'can' => ['manage' => $request->user()->canDo('it.manage')],
        ]);
    }

    public function store(StoreItMajorIncidentRequest $request)
    {
        $this->authorize('create', ItMajorIncident::class);
        $user = $request->user();
        $data = $this->creationData($user, $request->validated());

        try {
            $majorIncident = $this->majorIncidentService->create($user, $data);
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->route('it.major-incidents.show', $majorIncident)
            ->with('success', "Major incident {$majorIncident->ticket->reference} declared.");
    }

    public function show(Request $request, ItMajorIncident $majorIncident)
    {
        $user = $request->user();
        $majorIncident->loadMissing('ticket');
        abort_unless($majorIncident->ticket && $this->workAccess->canView($user, $majorIncident->ticket), 404);
        $this->authorize('view', $majorIncident);
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
            'links' => $this->presentLinks($links, $user),
            'options' => $this->options($majorIncident->ticket, $user),
            'can' => ['manage' => $this->workAccess->canWork($user, $majorIncident->ticket)],
        ]);
    }

    public function update(UpdateItMajorIncidentRequest $request, ItMajorIncident $majorIncident)
    {
        $user = $request->user();
        $majorIncident->loadMissing('ticket');
        abort_unless($majorIncident->ticket && $this->workAccess->canWork($user, $majorIncident->ticket), 404);
        $this->authorize('update', $majorIncident);
        try {
            $this->majorIncidentService->update($majorIncident, $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Major incident updated.');
    }

    public function storeUpdate(StoreItMajorIncidentUpdateRequest $request, ItMajorIncident $majorIncident)
    {
        $user = $request->user();
        $majorIncident->loadMissing('ticket');
        abort_unless($majorIncident->ticket && $this->workAccess->canWork($user, $majorIncident->ticket), 404);
        $this->authorize('update', $majorIncident);
        try {
            $this->majorIncidentService->postUpdate($majorIncident, $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Major incident update published.');
    }

    public function transition(TransitionItMajorIncidentRequest $request, ItMajorIncident $majorIncident)
    {
        $user = $request->user();
        $majorIncident->loadMissing('ticket');
        abort_unless($majorIncident->ticket && $this->workAccess->canWork($user, $majorIncident->ticket), 404);
        $this->authorize('update', $majorIncident);
        $data = $request->validated();

        try {
            $this->majorIncidentService->transition(
                $majorIncident,
                $request->user(),
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
        $user = $request->user();
        abort_unless($this->workAccess->canViewMajorIncidentStatus($user, $majorIncident), 404);
        $this->authorize('viewStatus', $majorIncident);
        $majorIncident->load('ticket');

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
    private function presentLinks(Collection $links, User $user): array
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
                if ($this->workAccess->canView($user, $target)) {
                    $values['incidents'][] = $this->ticketOption($target);
                }
            } elseif ($link->relationship === 'source_alert' && $target instanceof ControlRoomAlert) {
                $values['alert'] = ['id' => $target->id, 'reference' => $target->reference_number, 'title' => $target->alert_type];
            }
        }

        return $values;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function options(ItTicket $majorIncidentTicket, User $user): array
    {
        return [
            'agents' => $this->linkedOptions->agents($user, $majorIncidentTicket),
            'services' => $this->linkedOptions->services(),
            'sites' => $this->linkedOptions->sites($user),
            'incidents' => $this->linkedOptions->tickets($user, ['incident'], $majorIncidentTicket->id),
            'alerts' => $this->linkedOptions->alerts($user),
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

    /** @return array{id: int, name: string}|null */
    private function userOption(?Model $user): ?array
    {
        return $user ? ['id' => (int) $user->getKey(), 'name' => (string) $user->getAttribute('name')] : null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function creationData(User $user, array $data): array
    {
        $wide = (bool) ($data['is_organisation_wide'] ?? false);
        $siteWasSupplied = array_key_exists('site_id', $data);
        $siteId = $wide
            ? null
            : ($siteWasSupplied && $data['site_id'] !== null
                ? (int) $data['site_id']
                : $this->workAccess->defaultSiteId($user));

        if ($wide && $siteWasSupplied && $data['site_id'] !== null) {
            throw ValidationException::withMessages([
                'site_id' => 'Application-wide work cannot also have a Site.',
            ]);
        }

        if (! $this->workAccess->canAssignScope($user, $siteId, $wide)) {
            if ($siteWasSupplied || $wide) {
                abort(403);
            }

            throw ValidationException::withMessages([
                'site_id' => 'Choose an active approved Site for this major incident.',
            ]);
        }

        $data['site_id'] = $siteId;
        $data['is_organisation_wide'] = $wide;

        return $data;
    }
}
