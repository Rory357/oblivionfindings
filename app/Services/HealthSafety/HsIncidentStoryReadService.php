<?php

namespace App\Services\HealthSafety;

use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Incidents\IncidentOwnerEligibility;

/**
 * Q1 (Stephan, 31 Oct; G3 boards approved 1 Nov 2026): a team lead reads an incident's "What happened" and "What we
 * did straight away" on its safety review — and nothing else from the incident.
 *
 * This is a separate, narrow read. It never counts as reading the incident or the event: it is not consulted by
 * HsEventAccessService::canReadEvent(), readableEventForCommand(), owner eligibility, the WorkSafe decision, checks or
 * closing. It returns null unless every condition holds:
 *  - the person holds healthSafety.events.readIncidentStory and hazards.view;
 *  - the safety review comes from an incident that has been submitted (never a draft);
 *  - the incident is at one of the person's Sites (the incident's own Site boundary);
 *  - the incident is not restricted (safeguarding type, HR-confidential, a linked safeguarding concern, or owner
 *    exclusions) and not independently handled.
 */
final class HsIncidentStoryReadService
{
    public const PERMISSION = 'healthSafety.events.readIncidentStory';

    public const OWN_ACTIONS_PERMISSION = 'healthSafety.actions.ownOnStoryReviews';

    public function __construct(
        private readonly IncidentAccessService $incidents,
        private readonly IncidentOwnerEligibility $ownership,
    ) {}

    /** @return array{incident_id: int, what_happened: ?string, did_straight_away: ?string, reported_by: ?string}|null */
    public function storyFor(User $viewer, HsEvent $event): ?array
    {
        $eligible = $this->readableIncident($viewer, $event);
        if ($eligible === null) {
            return null;
        }
        // The report's words are read only once the story read is allowed (a refused read touches no incident body).
        $incident = ClientIncident::query()->whereKey($eligible->id)
            ->first(['id', 'description', 'immediate_action_taken', 'immediate_action', 'reported_by']);

        return [
            'incident_id' => (int) $eligible->id,
            'what_happened' => $incident?->description !== null ? (string) $incident->description : null,
            'did_straight_away' => $incident?->immediate_action_taken ?? $incident?->immediate_action,
            'reported_by' => $incident?->reporter?->name,
        ];
    }

    public function canReadStory(User $viewer, HsEvent $event): bool
    {
        return $this->readableIncident($viewer, $event) !== null;
    }

    /**
     * Answer 23 (Stephan, 1 Nov 2026): a story reader may own an action on the review, and start it, finish it and add
     * files to it. Returns the (locked) event only when the person can't read the event normally, holds
     * healthSafety.actions.ownOnStoryReviews, currently owns this action, and can read the incident's story. Anyone
     * who can read the event normally gets null and keeps the ordinary command path.
     */
    public function eventForOwnedAction(User $actor, HsEvent $event, HsCorrectiveAction $action, bool $forUpdate = true): ?HsEvent
    {
        if (! $actor->canDo(self::OWN_ACTIONS_PERMISSION)
            || (int) $action->hs_event_id !== (int) $event->id
            || (int) $action->assigned_to_user_id !== (int) $actor->id
            || app(HsEventAccessService::class)->canReadEvent($actor, $event)
            || ! $this->canReadStory($actor, $event)) {
            return null;
        }
        $query = HsEvent::query()->whereKey($event->id);

        return ($forUpdate ? $query->lockForUpdate() : $query->sharedLock())->first();
    }

    /** Answer 23: may this person be made owner of an action on this review through the story read alone? */
    public function canOwnActionThroughStory(User $person, HsEvent $event): bool
    {
        return $person->canDo(self::OWN_ACTIONS_PERMISSION) && $this->canReadStory($person, $event);
    }

    private function readableIncident(User $viewer, HsEvent $event): ?ClientIncident
    {
        if (! $viewer->canDo(self::PERMISSION) || ! $viewer->canDo('hazards.view')) {
            return null;
        }

        $incident = $this->sourceIncident($event);
        if ($incident === null || in_array($incident->status, [null, '', 'draft'], true)) {
            return null;
        }
        if ($incident->independent_handling_requested || $this->ownership->isRestricted($incident)) {
            return null;
        }
        if (! $this->incidents->applySiteBoundary(ClientIncident::query(), $viewer)->whereKey($incident->id)->exists()) {
            return null;
        }

        return $incident;
    }

    /** The incident behind the review: its canonical source, else the incident that links to it. Eligibility columns only. */
    private function sourceIncident(HsEvent $event): ?ClientIncident
    {
        $columns = ['id', 'status', 'type', 'is_hr_confidential', 'independent_handling_requested', 'site_id', 'client_id', 'hs_event_id'];
        $source = ltrim((string) $event->getRawOriginal('source_type'), '\\');
        if ($source === ClientIncident::class && $event->source_id !== null) {
            return ClientIncident::query()->whereKey((int) $event->source_id)->first($columns);
        }

        return ClientIncident::query()->where('hs_event_id', $event->id)->orderBy('id')->first($columns);
    }
}
