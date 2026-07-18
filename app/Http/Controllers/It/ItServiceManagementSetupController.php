<?php

namespace App\Http\Controllers\It;

use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItServiceManagementSetupService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\It\SaveItQueueRequest;
use App\Http\Requests\It\SaveItServiceRequest;
use App\Http\Requests\It\SaveItTeamRequest;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ItServiceManagementSetupController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly ItServiceManagementSetupService $setupService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ItTeam::class);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());

        $teams = ItTeam::query()
            ->forTenant($tenantId)
            ->with(['manager:id,name', 'members:id,name'])
            ->withCount([
                'tickets as open_tickets_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES),
                'tasks as open_tasks_count' => fn ($query) => $query->whereIn('status', ['pending', 'in_progress', 'blocked']),
                'queues', 'members',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (ItTeam $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'is_active' => $team->is_active,
                'manager' => $this->userOption($team->manager),
                'members' => $team->members->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'role' => $member->pivot->role,
                ])->values()->all(),
                'workload' => [
                    'open_tickets' => $team->open_tickets_count,
                    'open_tasks' => $team->open_tasks_count,
                    'queues' => $team->queues_count,
                    'members' => $team->members_count,
                ],
            ])->values();

        $queues = ItQueue::query()
            ->forTenant($tenantId)
            ->with('team:id,name')
            ->withCount([
                'tickets as open_tickets_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES),
                'tickets as unassigned_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES)->whereNull('assigned_to_user_id'),
                'tickets as sla_risk_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES)->whereIn('sla_state', ['at_risk', 'breached']),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (ItQueue $queue) => [
                'id' => $queue->id,
                'key' => $queue->key,
                'name' => $queue->name,
                'description' => $queue->description,
                'is_active' => $queue->is_active,
                'team' => $queue->team ? ['id' => $queue->team->id, 'name' => $queue->team->name] : null,
                'filter_rules' => $queue->filter_rules ?? [],
                'workload' => [
                    'open_tickets' => $queue->open_tickets_count,
                    'unassigned' => $queue->unassigned_count,
                    'sla_risk' => $queue->sla_risk_count,
                ],
            ])->values();

        $services = ItService::query()
            ->forTenant($tenantId)
            ->with('owner:id,name')
            ->withCount([
                'tickets as open_tickets_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES),
                'tickets as sla_risk_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES)->whereIn('sla_state', ['at_risk', 'breached']),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (ItService $service) => [
                'id' => $service->id,
                'key' => $service->key,
                'name' => $service->name,
                'description' => $service->description,
                'is_active' => $service->is_active,
                'status' => $service->status,
                'criticality' => $service->criticality,
                'owner' => $this->userOption($service->owner),
                'workload' => [
                    'open_tickets' => $service->open_tickets_count,
                    'sla_risk' => $service->sla_risk_count,
                ],
            ])->values();

        return Inertia::render('it/setup/index', [
            'teams' => $teams,
            'queues' => $queues,
            'services' => $services,
            'agents' => ItStaffDirectory::agents($tenantId)
                ->sortBy('name')
                ->map(fn (User $user) => $this->userOption($user))
                ->values(),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    public function storeTeam(SaveItTeamRequest $request)
    {
        $this->authorize('create', ItTeam::class);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->createTeam($request->user(), $tenantId, $request->validated()), 'Team created.');
    }

    public function updateTeam(SaveItTeamRequest $request, ItTeam $team)
    {
        $this->authorize('update', $team);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->updateTeam($team, $request->user(), $tenantId, $request->validated()), 'Team updated.');
    }

    public function storeQueue(SaveItQueueRequest $request)
    {
        $this->authorize('create', ItQueue::class);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->createQueue($request->user(), $tenantId, $request->validated()), 'Queue created.');
    }

    public function updateQueue(SaveItQueueRequest $request, ItQueue $queue)
    {
        $this->authorize('update', $queue);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->updateQueue($queue, $request->user(), $tenantId, $request->validated()), 'Queue updated.');
    }

    public function storeService(SaveItServiceRequest $request)
    {
        $this->authorize('create', ItService::class);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->createService($request->user(), $tenantId, $request->validated()), 'Service created.');
    }

    public function updateService(SaveItServiceRequest $request, ItService $service)
    {
        $this->authorize('update', $service);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->updateService($service, $request->user(), $tenantId, $request->validated()), 'Service updated.');
    }

    /** @param callable(int): mixed $action */
    private function run(Request $request, callable $action, string $success)
    {
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        try {
            $action($tenantId);
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', $success);
    }

    /** @return array{id: int, name: string}|null */
    private function userOption(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
