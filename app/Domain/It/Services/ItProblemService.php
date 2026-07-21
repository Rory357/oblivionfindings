<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Models\ItProblem;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Support\LegacyStorageContext;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ItProblemService
{
    public function __construct(
        private readonly ItWorkTransitionService $transitionService,
        private readonly ItTicketLinkService $linkService,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ItProblem
    {
        return DB::transaction(function () use ($actor, $data): ItProblem {
            if (! $this->workAccess->canAssignScope(
                $actor,
                $data['site_id'] ?? null,
                (bool) ($data['is_organisation_wide'] ?? false),
            )) {
                throw new DomainException('Choose an approved Site or authorised organisation-wide scope.');
            }

            $storageContextId = LegacyStorageContext::id();
            $ticket = ItTicket::createWithReference([
                'tenant_id' => $storageContextId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'requester_user_id' => $actor->id,
                'requested_for_user_id' => $actor->id,
                'category' => $data['category'],
                'priority' => $data['priority'],
                'site_id' => $data['site_id'],
                'is_organisation_wide' => $data['is_organisation_wide'],
                'impact' => 'organization',
                'urgency' => $data['priority'] === 'urgent' ? 'critical' : $data['priority'],
                'work_type' => 'problem',
                'workflow_state' => 'submitted',
                'source' => 'agent',
                'status' => 'open',
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();
            ItTicketEvent::record($ticket, 'created', $actor->id, ['source' => 'problem_management']);

            $problem = ItProblem::query()->create([
                'tenant_id' => $storageContextId,
                'ticket_id' => $ticket->id,
                ...Arr::only($data, ['impact_summary', 'root_cause', 'workaround', 'corrective_action']),
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->syncLinks($problem, $actor, $data);

            $this->transitionService->transition($ticket, new ItTransitionInput(
                actor: $actor,
                to: ItWorkflowState::Investigating,
                reason: 'Problem investigation opened',
                source: 'problem_management',
            ));

            return $problem->fresh('ticket');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItProblem $problem, User $actor, array $data): ItProblem
    {
        return DB::transaction(function () use ($problem, $actor, $data): ItProblem {
            $problem = $this->lockedProblem($problem, $actor);
            $ticket = $problem->ticket()->lockForUpdate()->firstOrFail();
            $this->validateLinkTargets($ticket, $data);

            $ticket->fill(Arr::only($data, ['title', 'description', 'category', 'priority', 'next_action']));
            $ticketChanged = array_keys($ticket->getDirty());
            $priorityChanged = $ticket->isDirty('priority');
            $ticket->save();
            if ($priorityChanged) {
                $ticket->stampSlaDueDates();
                $ticket->save();
            }

            $problem->fill(Arr::only($data, ['impact_summary', 'root_cause', 'workaround', 'corrective_action']));
            $problemChanged = array_keys($problem->getDirty());
            $problem->updated_by_user_id = $actor->id;
            $problem->save();
            $this->syncLinks($problem, $actor, $data);

            ItTicketEvent::record($ticket, 'problem_updated', $actor->id, [
                'ticket_fields' => $ticketChanged,
                'problem_fields' => $problemChanged,
                'links_updated' => array_key_exists('incident_ids', $data) || array_key_exists('permanent_fix_change_id', $data),
            ]);

            return $problem->fresh('ticket');
        });
    }

    public function transition(
        ItProblem $problem,
        User $actor,
        ItWorkflowState $state,
        string $reason,
        ?string $resolutionCode = null,
        ?string $resolutionSummary = null,
    ): ItProblem {
        return DB::transaction(function () use (
            $problem,
            $actor,
            $state,
            $reason,
            $resolutionCode,
            $resolutionSummary,
        ): ItProblem {
            $problem = $this->lockedProblem($problem, $actor);
            if ($state === ItWorkflowState::KnownError
                && (blank($problem->root_cause) || blank($problem->workaround))) {
                throw new DomainException('Root cause and workaround are required before publishing a known error.');
            }
            if ($state === ItWorkflowState::Resolved
                && (blank($problem->root_cause) || blank($problem->corrective_action))) {
                throw new DomainException('Root cause and corrective action are required before resolving a problem.');
            }

            $this->transitionService->transition($problem->ticket, new ItTransitionInput(
                actor: $actor,
                to: $state,
                reason: $reason,
                resolutionCode: $resolutionCode,
                resolutionSummary: $resolutionSummary,
                source: 'problem_management',
            ));

            if ($state === ItWorkflowState::KnownError && $problem->known_error_at === null) {
                $problem->known_error_at = now();
                $problem->updated_by_user_id = $actor->id;
                $problem->save();
            }

            return $problem->fresh('ticket');
        });
    }

    private function lockedProblem(ItProblem $problem, User $actor): ItProblem
    {
        $locked = ItProblem::query()
            ->whereKey($problem->id)
            ->with('ticket')
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->ticket || ! $this->workAccess->canWork($actor, $locked->ticket)) {
            throw new DomainException('You are not allowed to manage IT problems.');
        }

        return $locked;
    }

    /** @param array<string, mixed> $data */
    private function validateLinkTargets(ItTicket $problemTicket, array $data): void
    {
        if (array_key_exists('incident_ids', $data)) {
            $count = ItTicket::query()
                ->whereIn('id', (array) $data['incident_ids'])
                ->whereIn('work_type', ['incident', 'major_incident'])
                ->count();
            if ($count !== count(array_unique((array) $data['incident_ids']))) {
                throw new DomainException('Every affected record must be an incident.');
            }
        }
        if (! empty($data['permanent_fix_change_id'])
            && ! ItTicket::query()
                ->whereKey($data['permanent_fix_change_id'])
                ->where('work_type', 'change')
                ->exists()) {
            throw new DomainException('The permanent fix must be a change record.');
        }
    }

    /** @param array<string, mixed> $data */
    private function syncLinks(ItProblem $problem, User $actor, array $data): void
    {
        $ticket = $problem->ticket;
        $this->validateLinkTargets($ticket, $data);

        if (array_key_exists('incident_ids', $data)) {
            $this->syncReciprocalTicketLinks(
                $ticket,
                array_map('intval', (array) $data['incident_ids']),
                'related_incident',
                'related_problem',
                $actor,
            );
        }
        if (array_key_exists('permanent_fix_change_id', $data)) {
            $ids = $data['permanent_fix_change_id'] ? [(int) $data['permanent_fix_change_id']] : [];
            $this->syncReciprocalTicketLinks(
                $ticket,
                $ids,
                'related_change',
                'related_problem',
                $actor,
            );
        }
    }

    /** @param array<int, int> $targetIds */
    private function syncReciprocalTicketLinks(
        ItTicket $source,
        array $targetIds,
        string $relationship,
        string $reciprocalRelationship,
        User $actor,
    ): void {
        $targets = ItTicket::query()->whereIn('id', $targetIds)->get()->keyBy('id');
        $existing = $source->links()
            ->where('relationship', $relationship)
            ->where('linkable_type', (new ItTicket)->getMorphClass())
            ->get();

        foreach ($existing as $link) {
            if (! in_array((int) $link->linkable_id, $targetIds, true)) {
                $target = ItTicket::query()->find($link->linkable_id);
                if ($target) {
                    $this->linkService->unlink($source, $target, $relationship, $actor->id);
                    $this->linkService->unlink($target, $source, $reciprocalRelationship, $actor->id);
                } else {
                    throw new DomainException('A related work item no longer exists.');
                }
            }
        }

        foreach ($targets as $target) {
            $this->linkService->link($source, $target, $relationship, [], $actor->id);
            $this->linkService->link($target, $source, $reciprocalRelationship, [], $actor->id);
        }
    }
}
