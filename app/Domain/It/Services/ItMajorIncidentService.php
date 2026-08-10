<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\ItStaffDirectory;
use App\Models\ControlRoomAlert;
use App\Models\ItMajorIncident;
use App\Models\ItMajorIncidentUpdate;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\MajorIncidentUpdateNotification;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class ItMajorIncidentService
{
    public function __construct(
        private readonly ItWorkTransitionService $transitionService,
        private readonly ItTicketLinkService $linkService,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ItMajorIncident
    {
        return DB::transaction(function () use ($actor, $data): ItMajorIncident {
            if (! $this->workAccess->canAssignScope(
                $actor,
                $data['site_id'] ?? null,
                (bool) ($data['is_organisation_wide'] ?? false),
            )) {
                throw new DomainException('Choose an approved Site or authorised organisation-wide scope.');
            }

            $ticket = ItTicket::createWithReference([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'requester_user_id' => $actor->id,
                'requested_for_user_id' => $actor->id,
                'owner_user_id' => $actor->id,
                'category' => $data['category'],
                'priority' => $data['priority'],
                'site_id' => $data['site_id'],
                'is_organisation_wide' => $data['is_organisation_wide'],
                'impact' => 'organization',
                'urgency' => $data['priority'] === 'urgent' ? 'critical' : $data['priority'],
                'work_type' => 'major_incident',
                'workflow_state' => ItWorkflowState::Declared->value,
                'source' => 'agent',
                'status' => 'open',
                'requires_approval' => false,
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();
            ItTicketEvent::record($ticket, 'created', $actor->id, ['source' => 'major_incident_management']);
            $this->guardCommandUser($ticket, $data['communications_lead_user_id'] ?? null, 'communications lead');

            $majorIncident = ItMajorIncident::query()->create([
                'ticket_id' => $ticket->id,
                'severity' => $data['severity'],
                'impact_summary' => $data['impact_summary'],
                'commander_user_id' => $actor->id,
                'communications_lead_user_id' => $data['communications_lead_user_id'] ?? null,
                'target_update_minutes' => $data['target_update_minutes'],
                'declared_at' => now(),
                'next_update_due_at' => now()->addMinutes((int) $data['target_update_minutes']),
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->syncLinks($majorIncident, $actor, $data);
            AuditLogger::logOrFail('it.major_incident.created', $ticket, [
                'actor_id' => $actor->id,
                'major_incident_id' => $majorIncident->id,
                'site_id' => $ticket->site_id,
                'is_organisation_wide' => (bool) $ticket->is_organisation_wide,
                'severity' => $majorIncident->severity,
                'target_update_minutes' => $majorIncident->target_update_minutes,
                'application_scope' => 'single_application',
            ]);

            return $majorIncident->fresh(['ticket', 'commander', 'communicationsLead']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItMajorIncident $majorIncident, User $actor, array $data): ItMajorIncident
    {
        return DB::transaction(function () use ($majorIncident, $actor, $data): ItMajorIncident {
            $majorIncident = $this->lockedMajorIncident($majorIncident, $actor);
            $ticket = $majorIncident->ticket()->lockForUpdate()->firstOrFail();

            if (array_key_exists('commander_user_id', $data)) {
                $this->guardCommandUser($ticket, $data['commander_user_id'], 'incident commander');
            }
            if (array_key_exists('communications_lead_user_id', $data)) {
                $this->guardCommandUser($ticket, $data['communications_lead_user_id'], 'communications lead');
            }

            $ticket->fill(Arr::only($data, ['title', 'description', 'category', 'priority', 'next_action']));
            $ticketFields = array_keys($ticket->getDirty());
            $priorityChanged = $ticket->isDirty('priority');
            $ticket->save();
            if ($priorityChanged) {
                $ticket->stampSlaDueDates();
                $ticket->save();
            }

            $majorIncident->fill(Arr::only($data, [
                'severity', 'impact_summary', 'commander_user_id', 'communications_lead_user_id',
                'target_update_minutes', 'restoration_summary', 'root_cause_summary', 'review_summary',
            ]));
            $profileFields = array_keys($majorIncident->getDirty());
            if (array_key_exists('target_update_minutes', $data)
                && ! in_array($ticket->workflow_state, ['restored', 'resolved', 'review', 'closed'], true)) {
                $majorIncident->next_update_due_at = now()->addMinutes((int) $majorIncident->target_update_minutes);
            }
            $majorIncident->updated_by_user_id = $actor->id;
            $majorIncident->save();
            $this->syncLinks($majorIncident, $actor, $data);

            ItTicketEvent::record($ticket, 'major_incident_updated', $actor->id, [
                'ticket_fields' => $ticketFields,
                'major_incident_fields' => $profileFields,
                'links_updated' => $this->linksWereSupplied($data),
            ]);
            AuditLogger::logOrFail('it.major_incident.updated', $ticket, [
                'actor_id' => $actor->id,
                'major_incident_id' => $majorIncident->id,
                'ticket_fields' => $ticketFields,
                'major_incident_fields' => $profileFields,
                'links_updated' => $this->linksWereSupplied($data),
                'application_scope' => 'single_application',
            ]);

            return $majorIncident->fresh(['ticket', 'commander', 'communicationsLead']);
        });
    }

    /** @param array<string, mixed> $data */
    public function postUpdate(ItMajorIncident $majorIncident, User $actor, array $data): ItMajorIncidentUpdate
    {
        return DB::transaction(function () use ($majorIncident, $actor, $data): ItMajorIncidentUpdate {
            $majorIncident = $this->lockedMajorIncident($majorIncident, $actor);
            $update = $majorIncident->updates()->create([
                ...Arr::only($data, ['update_kind', 'audience', 'summary', 'service_status']),
                'published_at' => now(),
                'author_user_id' => $actor->id,
            ]);

            if (! in_array($majorIncident->ticket->workflow_state, ['restored', 'resolved', 'review', 'closed'], true)) {
                $majorIncident->next_update_due_at = now()->addMinutes($majorIncident->target_update_minutes);
            }
            $majorIncident->updated_by_user_id = $actor->id;
            $majorIncident->save();

            ItTicketEvent::record($majorIncident->ticket, 'major_incident_update_published', $actor->id, [
                'update_id' => $update->id,
                'audience' => $update->audience,
                'update_kind' => $update->update_kind,
            ]);
            AuditLogger::logOrFail('it.major_incident.update.published', $majorIncident->ticket, [
                'actor_id' => $actor->id,
                'major_incident_id' => $majorIncident->id,
                'update_id' => $update->id,
                'audience' => $update->audience,
                'update_kind' => $update->update_kind,
                'service_status' => $update->service_status,
                'application_scope' => 'single_application',
            ]);

            if (in_array($update->audience, ['staff', 'public'], true)) {
                $recipients = User::query()
                    ->whereIn('id', $this->affectedRequesterIds($majorIncident->ticket))
                    ->get();
                Notification::send($recipients, new MajorIncidentUpdateNotification($majorIncident->loadMissing('ticket'), $update));
            }

            return $update->fresh('author');
        });
    }

    public function transition(
        ItMajorIncident $majorIncident,
        User $actor,
        ItWorkflowState $state,
        string $reason,
        ?string $resolutionCode = null,
        ?string $resolutionSummary = null,
    ): ItMajorIncident {
        return DB::transaction(function () use ($majorIncident, $actor, $state, $reason, $resolutionCode, $resolutionSummary): ItMajorIncident {
            $majorIncident = $this->lockedMajorIncident($majorIncident, $actor);
            $this->guardTransition($majorIncident, $state);

            $this->transitionService->transition($majorIncident->ticket, new ItTransitionInput(
                actor: $actor,
                to: $state,
                reason: $reason,
                resolutionCode: $resolutionCode,
                resolutionSummary: $resolutionSummary,
                source: 'major_incident_management',
            ));

            if (in_array($state, [ItWorkflowState::Restored, ItWorkflowState::Resolved], true)) {
                $majorIncident->restored_at ??= now();
                $majorIncident->next_update_due_at = null;
            }
            if ($state === ItWorkflowState::Review) {
                $majorIncident->reviewed_at ??= now();
            }
            if (in_array($state, [ItWorkflowState::Resolved, ItWorkflowState::Closed], true)) {
                $majorIncident->next_update_due_at = null;
            }
            if ($state === ItWorkflowState::Declared) {
                $majorIncident->next_update_due_at = now()->addMinutes($majorIncident->target_update_minutes);
            }
            $majorIncident->updated_by_user_id = $actor->id;
            $majorIncident->save();

            return $majorIncident->fresh('ticket');
        });
    }

    private function lockedMajorIncident(ItMajorIncident $majorIncident, User $actor): ItMajorIncident
    {
        $locked = ItMajorIncident::query()
            ->whereKey($majorIncident->id)
            ->with('ticket')
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->ticket || ! $this->workAccess->canWork($actor, $locked->ticket)) {
            throw new DomainException('You are not allowed to manage major incidents.');
        }

        return $locked;
    }

    private function guardCommandUser(ItTicket $ticket, mixed $userId, string $label): void
    {
        if ($userId === null || $userId === '') {
            return;
        }
        if (! ItStaffDirectory::agentsForTicket($ticket)->contains('id', (int) $userId)) {
            throw new DomainException("The {$label} must be a current IT technician with access to this Site.");
        }
    }

    private function guardTransition(ItMajorIncident $majorIncident, ItWorkflowState $state): void
    {
        if ($state === ItWorkflowState::Responding
            && ($majorIncident->commander_user_id === null || blank($majorIncident->impact_summary))) {
            throw new DomainException('An incident commander and impact summary are required before response begins.');
        }
        if (in_array($state, [ItWorkflowState::Restored, ItWorkflowState::Resolved], true)
            && blank($majorIncident->restoration_summary)) {
            throw new DomainException('Restoration evidence is required before service can be marked restored.');
        }
        if ($state === ItWorkflowState::Resolved && blank($majorIncident->root_cause_summary)) {
            throw new DomainException('A root-cause summary is required before resolution.');
        }
        if ($state === ItWorkflowState::Review && blank($majorIncident->review_summary)) {
            throw new DomainException('A post-incident review summary is required before review.');
        }
        if ($state === ItWorkflowState::Closed
            && (blank($majorIncident->review_summary) || $majorIncident->reviewed_at === null)) {
            throw new DomainException('A completed post-incident review is required before closure.');
        }
    }

    /** @param array<string, mixed> $data */
    private function syncLinks(ItMajorIncident $majorIncident, User $actor, array $data): void
    {
        $ticket = $majorIncident->ticket;
        $this->syncExternalLinks($ticket, $actor, $data, 'service_ids', ItService::class, 'affected_service');
        $this->syncExternalLinks($ticket, $actor, $data, 'site_ids', Site::class, 'affected_site');
        $this->syncControlRoomAlert($ticket, $actor, $data);
        $this->syncIncidentLinks($ticket, $actor, $data);
    }

    /** @param class-string<Model> $modelClass @param array<string, mixed> $data */
    private function syncExternalLinks(ItTicket $ticket, User $actor, array $data, string $key, string $modelClass, string $relationship): void
    {
        if (! array_key_exists($key, $data)) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', (array) $data[$key])));
        $targets = $modelClass::query()->whereIn('id', $ids)->get()->keyBy('id');
        if ($targets->count() !== count($ids)) {
            throw new DomainException('One or more linked operational records no longer exist.');
        }
        $existing = $ticket->links()
            ->where('relationship', $relationship)
            ->where('linkable_type', (new $modelClass)->getMorphClass())
            ->whereNotIn('linkable_id', $ids === [] ? [-1] : $ids)
            ->get();
        foreach ($existing as $link) {
            $target = $modelClass::query()->find($link->linkable_id);
            if (! $target) {
                throw new DomainException('A linked operational record no longer exists.');
            }
            $this->linkService->unlink($ticket, $target, $relationship, $actor->id);
        }
        foreach ($targets as $target) {
            $this->linkService->link($ticket, $target, $relationship, [], $actor->id);
        }
    }

    /** @param array<string, mixed> $data */
    private function syncControlRoomAlert(ItTicket $ticket, User $actor, array $data): void
    {
        if (! array_key_exists('control_room_alert_id', $data)) {
            return;
        }
        $existing = $ticket->links()
            ->where('relationship', 'source_alert')
            ->where('linkable_type', (new ControlRoomAlert)->getMorphClass())
            ->get();
        foreach ($existing as $link) {
            $alert = ControlRoomAlert::query()->find($link->linkable_id);
            if (! $alert) {
                throw new DomainException('A linked Control Room alert no longer exists.');
            }
            $this->linkService->unlink($ticket, $alert, 'source_alert', $actor->id);
        }
        if (! empty($data['control_room_alert_id'])) {
            $alert = ControlRoomAlert::query()->findOrFail((int) $data['control_room_alert_id']);
            $this->linkService->link($ticket, $alert, 'source_alert', ['canonical_owner' => 'control_room'], $actor->id);
        }
    }

    /** @param array<string, mixed> $data */
    private function syncIncidentLinks(ItTicket $majorIncidentTicket, User $actor, array $data): void
    {
        if (! array_key_exists('incident_ids', $data)) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', (array) $data['incident_ids'])));
        $incidents = ItTicket::query()
            ->whereIn('id', $ids)
            ->where('work_type', 'incident')
            ->get()
            ->keyBy('id');
        if ($incidents->count() !== count($ids)) {
            throw new DomainException('Every affected record must be an incident.');
        }

        $ticketType = (new ItTicket)->getMorphClass();
        $existing = $majorIncidentTicket->links()
            ->where('relationship', 'related_incident')
            ->where('linkable_type', $ticketType)
            ->get();
        foreach ($existing as $link) {
            if (! in_array((int) $link->linkable_id, $ids, true)) {
                $incident = ItTicket::query()->find($link->linkable_id);
                if ($incident) {
                    $this->linkService->unlink($majorIncidentTicket, $incident, 'related_incident', $actor->id);
                    $this->linkService->unlink($incident, $majorIncidentTicket, 'major_incident_member', $actor->id);
                } else {
                    throw new DomainException('A related incident no longer exists.');
                }
            }
        }
        foreach ($incidents as $incident) {
            $this->linkService->link($majorIncidentTicket, $incident, 'related_incident', [], $actor->id);
            $this->linkService->link($incident, $majorIncidentTicket, 'major_incident_member', [], $actor->id);
        }
    }

    /** @return array<int, int> */
    private function affectedRequesterIds(ItTicket $ticket): array
    {
        $incidentIds = $ticket->links()
            ->where('relationship', 'related_incident')
            ->where('linkable_type', (new ItTicket)->getMorphClass())
            ->pluck('linkable_id');

        return ItTicket::query()
            ->whereIn('id', $incidentIds)
            ->whereNotNull('requester_user_id')
            ->pluck('requester_user_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    private function linksWereSupplied(array $data): bool
    {
        return array_intersect(array_keys($data), ['service_ids', 'site_ids', 'incident_ids', 'control_room_alert_id']) !== [];
    }
}
