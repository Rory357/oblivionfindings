<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItChangeService;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\It\StoreItChangeRequest;
use App\Http\Requests\It\TransitionItChangeRequest;
use App\Http\Requests\It\UpdateItChangeRequest;
use App\Models\ControlRoomAlert;
use App\Models\ItChange;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Site;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class ItChangeController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly ItChangeService $changeService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ItChange::class);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $period = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $filters = [
            'type' => trim((string) $request->query('type', '')),
            'risk' => trim((string) $request->query('risk', '')),
            'state' => trim((string) $request->query('state', '')),
            'q' => trim((string) $request->query('q', '')),
            'from' => (string) ($period['from'] ?? ''),
            'to' => (string) ($period['to'] ?? ''),
        ];

        $changes = ItChange::query()
            ->forTenant($tenantId)
            ->with('ticket:id,tenant_id,reference,title,priority,status,workflow_state,next_action,requires_approval,updated_at')
            ->when($filters['type'] !== '', fn ($query) => $query->where('change_type', $filters['type']))
            ->when($filters['risk'] !== '', fn ($query) => $query->where('risk_level', $filters['risk']))
            ->when($filters['state'] !== '', fn ($query) => $query->whereHas('ticket', fn ($ticket) => $ticket->where('workflow_state', $filters['state'])))
            ->when($filters['from'] !== '', fn ($query) => $query->whereDate('validated_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($query) => $query->whereDate('validated_at', '<=', $filters['to']))
            ->when($filters['q'] !== '', function ($query) use ($filters) {
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
            ->through(fn (ItChange $change) => $this->changeRow($change));

        return Inertia::render('it/changes/index', [
            'changes' => $changes,
            'filters' => array_map(fn (string $value) => $value !== '' ? $value : null, $filters),
            'can' => ['manage' => $request->user()->canDo('it.manage')],
        ]);
    }

    public function store(StoreItChangeRequest $request)
    {
        $this->authorize('create', ItChange::class);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());

        try {
            $change = $this->changeService->create($request->user(), $tenantId, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->route('it.changes.show', $change)->with('success', "Change {$change->ticket->reference} opened.");
    }

    public function show(Request $request, ItChange $change)
    {
        $this->authorize('view', $change);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $change->tenant_id);
        $change->load(['ticket', 'implementedBy:id,name', 'validatedBy:id,name', 'reviewedBy:id,name']);
        $ticket = $change->ticket;
        $ticket->loadCount(['comments', 'tasks', 'approvals', 'attachments', 'events']);
        $ticket->load(['approvals' => fn ($query) => $query->with('requester:id,name', 'approver:id,name')->latest('id')->limit(1)]);
        $links = $ticket->links()->with('linkable')->get();

        return Inertia::render('it/changes/show', [
            'change' => [
                'id' => $change->id,
                'change_type' => $change->change_type,
                'risk_level' => $change->risk_level,
                'is_restricted' => $change->is_restricted,
                'impact_summary' => $change->impact_summary,
                'implementation_plan' => $change->implementation_plan,
                'validation_plan' => $change->validation_plan,
                'backout_plan' => $change->backout_plan,
                'maintenance_starts_at' => $change->maintenance_starts_at?->toIso8601String(),
                'maintenance_ends_at' => $change->maintenance_ends_at?->toIso8601String(),
                'maintenance_state' => $this->maintenanceState($change),
                'actual_outcome' => $change->actual_outcome,
                'validation_result' => $change->validation_result,
                'validation_summary' => $change->validation_summary,
                'backout_summary' => $change->backout_summary,
                'pir_summary' => $change->pir_summary,
                'implemented_at' => $change->implemented_at?->toIso8601String(),
                'implemented_by' => $this->userOption($change->implementedBy),
                'validated_at' => $change->validated_at?->toIso8601String(),
                'validated_by' => $this->userOption($change->validatedBy),
                'backed_out_at' => $change->backed_out_at?->toIso8601String(),
                'reviewed_at' => $change->reviewed_at?->toIso8601String(),
                'reviewed_by' => $this->userOption($change->reviewedBy),
            ],
            'ticket' => [
                ...$this->ticketOption($ticket),
                'description' => $ticket->description,
                'category' => $ticket->category,
                'next_action' => $ticket->next_action,
                'requires_approval' => $ticket->requires_approval,
                'approval' => $ticket->approvals->first() ? [
                    'id' => $ticket->approvals->first()->id,
                    'status' => $ticket->approvals->first()->status,
                    'reason' => $ticket->approvals->first()->reason,
                    'requester' => $this->userOption($ticket->approvals->first()->requester),
                    'approver' => $this->userOption($ticket->approvals->first()->approver),
                ] : null,
                'sla_state' => $ticket->sla_state,
                'comments_count' => $ticket->comments_count,
                'tasks_count' => $ticket->tasks_count,
                'approvals_count' => $ticket->approvals_count,
                'attachments_count' => $ticket->attachments_count,
                'events_count' => $ticket->events_count,
            ],
            'links' => $this->presentLinks($links),
            'options' => $this->options($tenantId),
            'can' => ['manage' => $request->user()->canDo('it.manage')],
        ]);
    }

    public function update(UpdateItChangeRequest $request, ItChange $change)
    {
        $this->authorize('update', $change);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $change->tenant_id);

        try {
            $this->changeService->update($change, $request->user(), $tenantId, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Change updated.');
    }

    public function transition(TransitionItChangeRequest $request, ItChange $change)
    {
        $this->authorize('update', $change);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $change->tenant_id);
        $data = $request->validated();

        try {
            $this->changeService->transition(
                $change,
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

        return redirect()->back()->with('success', 'Change state updated.');
    }

    /** @return array<string, mixed> */
    private function changeRow(ItChange $change): array
    {
        return [
            ...$this->ticketOption($change->ticket),
            'change_id' => $change->id,
            'change_type' => $change->change_type,
            'risk_level' => $change->risk_level,
            'is_restricted' => $change->is_restricted,
            'impact_summary' => $change->impact_summary,
            'maintenance_starts_at' => $change->maintenance_starts_at?->toIso8601String(),
            'maintenance_ends_at' => $change->maintenance_ends_at?->toIso8601String(),
            'maintenance_state' => $this->maintenanceState($change),
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function presentLinks(Collection $links): array
    {
        $values = [
            'services' => [], 'sites' => [], 'devices' => [], 'alerts' => [], 'incidents' => [], 'problems' => [],
        ];
        foreach ($links as $link) {
            if (! $link instanceof ItTicketLink || ! $link->linkable instanceof Model) {
                continue;
            }
            $target = $link->linkable;
            if ($link->relationship === 'affected_service' && $target instanceof ItService) {
                $values['services'][] = ['id' => $target->id, 'name' => $target->name, 'status' => $target->status];
            } elseif ($link->relationship === 'affected_site' && $target instanceof Site) {
                $values['sites'][] = ['id' => $target->id, 'name' => $target->name, 'city' => $target->city];
            } elseif ($link->relationship === 'affected_device' && $target instanceof Device) {
                $values['devices'][] = ['id' => $target->id, 'name' => $target->name, 'uid' => $target->device_uid];
            } elseif ($link->relationship === 'source_alert' && $target instanceof ControlRoomAlert) {
                $values['alerts'][] = ['id' => $target->id, 'reference' => $target->reference_number, 'title' => $target->alert_type];
            } elseif ($link->relationship === 'related_incident' && $target instanceof ItTicket) {
                $values['incidents'][] = $this->ticketOption($target);
            } elseif ($link->relationship === 'related_problem' && $target instanceof ItTicket) {
                $values['problems'][] = $this->ticketOption($target);
            }
        }

        return $values;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function options(int $tenantId): array
    {
        return [
            'services' => ItService::query()->forTenant($tenantId)->where('is_active', true)->orderBy('name')->limit(100)->get()
                ->map(fn (ItService $service) => ['id' => $service->id, 'name' => $service->name])->all(),
            'sites' => Site::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('name')->limit(100)->get()
                ->map(fn (Site $site) => ['id' => $site->id, 'name' => $site->name])->all(),
            'devices' => Device::query()->forTenant($tenantId)->orderBy('name')->limit(100)->get()
                ->map(fn (Device $device) => ['id' => $device->id, 'name' => $device->name, 'uid' => $device->device_uid])->all(),
            'alerts' => ControlRoomAlert::query()->whereHas('site', fn ($site) => $site->where('tenant_id', $tenantId))->latest('id')->limit(100)->get()
                ->map(fn (ControlRoomAlert $alert) => ['id' => $alert->id, 'name' => ($alert->reference_number ?: 'Alert '.$alert->id).' · '.$alert->alert_type])->all(),
            'incidents' => ItTicket::query()->forTenant($tenantId)->whereIn('work_type', ['incident', 'major_incident'])->latest('id')->limit(100)->get()
                ->map(fn (ItTicket $ticket) => $this->ticketOption($ticket))->all(),
            'problems' => ItTicket::query()->forTenant($tenantId)->where('work_type', 'problem')->latest('id')->limit(100)->get()
                ->map(fn (ItTicket $ticket) => $this->ticketOption($ticket))->all(),
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

    private function maintenanceState(ItChange $change): string
    {
        if (in_array($change->ticket->workflow_state, ['completed', 'failed', 'backed_out', 'review', 'closed'], true)) {
            return 'finished';
        }
        if ($change->maintenance_starts_at === null || $change->maintenance_ends_at === null) {
            return $change->change_type === 'emergency' ? 'emergency' : 'unscheduled';
        }
        if (now()->lt($change->maintenance_starts_at)) {
            return 'upcoming';
        }
        if (now()->lte($change->maintenance_ends_at)) {
            return 'active';
        }

        return 'overdue';
    }
}
