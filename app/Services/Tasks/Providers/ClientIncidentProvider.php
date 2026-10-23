<?php

namespace App\Services\Tasks\Providers;

use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\Incidents\IncidentTaskReadService;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Incidents\IncidentOwnershipService;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\SplittableTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use App\Services\Tasks\TaskSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Projects canonical incident ownership. Assignment opens the incident's
 * governed command: the generic queue picker has no revision/reason contract.
 *
 * IS splittable: a queue row can be forked into an IncidentFollowup, mirroring
 * IncidentFollowupController::store() exactly (permission, columns, scoping).
 */
class ClientIncidentProvider implements ContextualHealthSafetyTaskProvider, HasModelClass, SiteScopedTaskProvider, SplittableTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'incident';
    }

    public function label(): string
    {
        return 'Client Incidents';
    }

    public function modelClass(): string
    {
        return ClientIncident::class;
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/incidents.php: permission:incidents.viewAny|incidents.viewAssigned.
        return $user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        try {
            return HealthSafetyReadContext::within($user, array_unique([
                ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
                ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS,
            ]), fn (HealthSafetyReadContext $context): array => $this->authorizedTasksInContext($user, $filters, $context));
        } catch (HttpException $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) { throw $denial; }
            return [];
        }
    }

    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(IncidentTaskReadService::PERMISSIONS,
            fn (User $current, CurrentAuthorizationReads $reads): array => $this->materialize($current, $filters, $context, $reads));
    }

    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(IncidentTaskReadService::PERMISSIONS,
            fn (User $current, CurrentAuthorizationReads $reads): array => $this->materialize($current, [], $context, $reads, true));
    }
    private function materialize(User $user, array $filters, HealthSafetyReadContext $context, CurrentAuthorizationReads $reads, bool $navigation = false): array
    {
        $leaf = app(IncidentTaskReadService::class);
        $includeSearchContext = ! $navigation && TaskSearch::hasQuery($filters);
        $with = [
            'client:id,first_name,last_name',
            'site:id,name',
            'incidentOwner.roles.permissions',
            'incidentOwner.permissionOverrides',
            $includeSearchContext
                ? 'controlRoomAlert:id,reference_number,assigned_to_user_id'
                : 'controlRoomAlert:id,reference_number',
            $includeSearchContext
                ? 'hsEvent:id,reference_number,owner_user_id'
                : 'hsEvent:id,reference_number',
        ];

        if ($includeSearchContext) {
            array_push(
                $with,
                'followups:id,client_incident_id,assigned_to_user_id',
                'followups.assignedTo:id,name',
                'controlRoomAlert.assignedTo:id,name',
                'controlRoomAlert.tasks:id,alert_id,title,description,assigned_to_user_id',
                'controlRoomAlert.tasks.assignedTo:id,name',
                'hsEvent.owner:id,name',
                'hsEvent.investigations:id,hs_event_id,reference_number,lead_investigator_id',
                'hsEvent.investigations.leadInvestigator:id,name',
                'hsEvent.correctiveActions:id,hs_event_id,reference_number,assigned_to_user_id',
                'hsEvent.correctiveActions.assignedTo:id,name',
            );
        }

        $parents = $leaf->incidentParents($user, $reads, $filters);
        $query = (clone $parents)
            ->with($leaf->relations($navigation ? [] : $with, $reads))
            ->when(
                $includeSearchContext,
                fn ($q) => TaskSearch::applyIncidentJourneyPredicate($q, $filters),
            )
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('occurred_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        if ($navigation) { $query->select([...\App\Services\Incidents\IncidentRoutingService::SOURCE_COLUMNS, 'created_at', 'occurred_at']); }
        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['closed']);
        }

        return app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            fn ($scoped, User $actor) => $leaf->current($scoped, $reads),
            function (ClientIncident $incident) use ($includeSearchContext, $user, $context, $reads, $parents, $leaf, $navigation) {
                if (! $leaf->isStillIncident($incident, $parents, $reads)) { return null; }
                $ownership = app(IncidentOwnershipService::class)->summaryFromProtectedSourceInCurrentReads($user, $incident, $reads, $parents);
                if ($ownership === null) { return null; }
                if ($navigation) {
                    if (! $leaf->isStillIncident($incident, $parents, $reads)) { return null; }
                    return new TaskItem(id: 'incident-'.$incident->id, source: $this->sourceKey(), sourceLabel: $this->label(), ref: null, title: '',
                        status: (string) $incident->status, bucket: match ($incident->status) { 'closed' => TaskItem::BUCKET_DONE, 'submitted', 'reviewed' => TaskItem::BUCKET_IN_PROGRESS, default => TaskItem::BUCKET_OPEN },
                        severity: 'info', assignee: in_array($ownership['state'], ['assigned', 'historical'], true) && $ownership['owner'] ? ['id' => $ownership['owner']['id'], 'name' => ''] : null);
                }
                $journey = IncidentJourneyTaskContext::make(
                    $incident,
                    $user,
                    includeSearchContext: $includeSearchContext,
                    readContext: $context,
                );

                $journey['incident_owner'] = $ownership;
                if (! $leaf->isStillIncident($incident, $parents, $reads)) { return null; }

                return new TaskItem(
                    id: 'incident-'.$incident->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $incident->reference_number,
                    title: $incident->title
                        ?: ucfirst(str_replace('_', ' ', (string) $incident->type)).' incident',
                    status: (string) $incident->status,
                    bucket: match ($incident->status) {
                        'closed' => TaskItem::BUCKET_DONE,
                        'submitted', 'reviewed' => TaskItem::BUCKET_IN_PROGRESS,
                        default => TaskItem::BUCKET_OPEN,
                    },
                    severity: TaskItem::normaliseSeverity($incident->severity),
                    assignee: in_array($ownership['state'], ['assigned', 'historical'], true) ? $ownership['owner'] : null,
                    client: $journey['person'] ?? null,
                    site: $journey['site'] ?? null,
                    dueAt: null,
                    createdAt: optional($incident->created_at)->toIso8601String(),
                    link: "/incidents/{$incident->id}",
                    type: 'Incident',
                    description: $incident->description ? str($incident->description)->limit(140)->toString() : null,
                    journey: $journey,
                    sourceContext: str_replace('_', ' ', (string) ($incident->source ?: 'incident report')),
                    actionLabel: match ($ownership['state']) {
                        'unassigned' => 'Assign incident owner',
                        'replacement_needed' => 'Replace incident owner',
                        default => 'Open incident',
                    },
                );
            },
        );
    }

    public function childLabel(): string
    {
        return 'follow-up';
    }

    public function createChild(User $actor, int $id, array $data): ?string
    {
        // Mirror IncidentFollowupController::store()'s permission gate. The
        // parent `view` policy is enforced by the re-fetch scoping below; the
        // followups.manage key is the write gate the controller adds on top.
        if (! $actor->canDo('incidents.followups.manage')) {
            throw ValidationException::withMessages([
                'title' => 'You do not have permission to add a follow-up to this incident.',
            ]);
        }

        // Map the cross-cutting split shape onto the follow-up columns exactly
        // as IncidentFollowupController::store() writes them. The queue has no
        // separate body column, so title (+ description) become the notes.
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $notes = $description !== '' ? $title."\n\n".$description : $title;

        $childLink = DB::transaction(function () use ($actor, $id, $data, $notes): string {
            // Re-fetch with the SAME viewAssigned client scoping authorizedTasks() applies,
            // so an incident outside the actor's assigned clients reads as absent.
            // Lock the parent before checking lifecycle state or inserting work so
            // this writer serialises with incident closure.
            $incident = ClientIncident::query()
                ->tap(fn ($query) => app(IncidentAccessService::class)->apply($query, $actor))
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $incident) {
                throw ValidationException::withMessages([
                    'title' => 'Incident not found or outside your assigned clients.',
                ]);
            }

            if ($incident->status === 'closed') {
                throw ValidationException::withMessages([
                    'title' => 'Closed incidents cannot receive new follow-ups. Reopen the incident before creating more work.',
                ]);
            }

            IncidentFollowup::create([
                'client_incident_id' => $incident->id,
                'assigned_to_user_id' => $data['assignee_id'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);

            return "/incidents/{$incident->id}?section=followups";
        }, 3);

        // The /tasks split controller sends the assignment FYI — do NOT fire
        // the module's own followups.created notification (that would double up
        // and fan out to managers the queue action deliberately excludes).

        return $childLink;
    }
}
