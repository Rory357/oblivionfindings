<?php

namespace App\Services\Tasks\Providers;

use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SplittableTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskSearch;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * NOT assignable from the queue: `investigation_assigned_to` is a legacy
 * column no incidents-module endpoint writes any more (investigation
 * editing moved to the H&S register — see IncidentController::update()'s
 * Option B note), so there are no module assignment rules to mirror.
 *
 * IS splittable: a queue row can be forked into an IncidentFollowup, mirroring
 * IncidentFollowupController::store() exactly (permission, columns, scoping).
 */
class ClientIncidentProvider implements HasModelClass, SplittableTaskProvider, TaskProvider
{
    private const SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites', 'reports.viewAny'];

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

    public function tasks(User $user, array $filters = []): array
    {
        $includeSearchContext = TaskSearch::hasQuery($filters);
        $with = [
            'client:id,first_name,last_name',
            'site:id,name',
            'investigator:id,name',
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

        $query = ClientIncident::query()
            ->with($with)
            ->tap(fn ($q) => app(UserSiteAccessService::class)->applyClientIncidentScope(
                $q,
                $user,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            // viewAssigned-only staff see just their assigned clients' incidents,
            // exactly as IncidentController::index scopes the register.
            ->when(
                ! $user->canDo('incidents.viewAny') && $user->canDo('incidents.viewAssigned'),
                fn ($q) => $q->whereHas('client.supportWorkers', fn ($qq) => $qq->whereKey($user->id)),
            )
            ->when(
                $includeSearchContext,
                fn ($q) => TaskSearch::applyIncidentJourneyPredicate($q, $filters),
            )
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('occurred_at')
            ->when(! $includeSearchContext, fn ($q) => $q->limit(300));

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['closed']);
        }

        return $query->get()->map(function (ClientIncident $incident) use ($includeSearchContext) {
            $journey = IncidentJourneyTaskContext::make(
                $incident,
                includeSearchContext: $includeSearchContext,
            );

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
                assignee: $incident->investigator
                    ? ['id' => $incident->investigator->id, 'name' => $incident->investigator->name]
                    : null,
                client: $journey['person'] ?? null,
                site: $journey['site'] ?? null,
                dueAt: null,
                createdAt: optional($incident->created_at)->toIso8601String(),
                link: "/incidents?incident={$incident->id}",
                type: 'Incident',
                description: $incident->description ? str($incident->description)->limit(140)->toString() : null,
                journey: $journey,
                sourceContext: str_replace('_', ' ', (string) ($incident->source ?: 'incident report')),
                actionLabel: 'Review incident',
            );
        })->all();
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
            // Re-fetch with the SAME viewAssigned client scoping tasks() applies,
            // so an incident outside the actor's assigned clients reads as absent.
            // Lock the parent before checking lifecycle state or inserting work so
            // this writer serialises with incident closure.
            $incident = ClientIncident::query()
                ->tap(fn ($query) => app(UserSiteAccessService::class)->applyClientIncidentScope(
                    $query,
                    $actor,
                    self::SITE_BYPASS_PERMISSIONS,
                ))
                ->when(
                    ! $actor->canDo('incidents.viewAny') && $actor->canDo('incidents.viewAssigned'),
                    fn ($q) => $q->whereHas('client.supportWorkers', fn ($qq) => $qq->whereKey($actor->id)),
                )
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

            return "/incidents?incident={$incident->id}";
        }, 3);

        // The /tasks split controller sends the assignment FYI — do NOT fire
        // the module's own followups.created notification (that would double up
        // and fan out to managers the queue action deliberately excludes).

        return $childLink;
    }
}
