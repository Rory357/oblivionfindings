<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;
use App\Services\UserSiteAccessService;

class ClientProfileSectionAccess
{
    public function __construct(
        private readonly ClientFamilyCommunicationAccess $familyCommunicationAccess,
        private readonly ClientWorkerEligibility $workerEligibility,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function canViewTimeline(User $user, Client $client): bool
    {
        return $this->canViewTimelineForAssignment(
            $user,
            $this->isAssignedCareWorker($user, $client),
        );
    }

    public function canViewBehaviour(User $user, Client $client): bool
    {
        return $this->canViewBehaviourForAssignment(
            $user,
            $this->isAssignedCareWorker($user, $client),
        );
    }

    /** @return array<string, bool> */
    public function for(User $user, Client $client): array
    {
        $assignedCareWorker = $this->isAssignedCareWorker($user, $client);
        $canUpdateClient = $user->can('update', $client);
        $dailyLiving = $canUpdateClient
            || $assignedCareWorker
            || (
                $user->canDo('clients.assignments.update')
                && $this->canAccessClientSite($user, $client, ['clients.assignments.update'])
            );
        $personalAssets = $canUpdateClient
            || $user->canDo('assets.viewAny')
            || ($assignedCareWorker && $user->canDo('assets.viewAssigned'));
        $portalAccess = $this->familyCommunicationAccess->canView($user, $client)
            || $this->familyCommunicationAccess->canManage($user, $client)
            || $canUpdateClient;
        $familyNotes = $this->familyCommunicationAccess->canView($user, $client);
        $documents = $canUpdateClient;
        $assessments = $user->canDo('clinical.assessments.viewAny')
            || ($assignedCareWorker && (
                $user->canDo('clinical.observations.viewAssigned')
                || $user->canDo('clinical.events.viewAssigned')
            ));
        $behaviour = $this->canViewBehaviourForAssignment($user, $assignedCareWorker);
        $carePlans = $user->canDo('care_plans.viewAny');
        $notes = $user->canDo('progress_notes.viewAny');
        $consents = $user->canDo('consents.viewAny');
        $risks = $user->canDo('risks.viewAny')
            || ($assignedCareWorker && $user->canDo('risks.viewAssigned'));

        return [
            'notes' => $notes,
            'timeline' => $this->canViewTimelineForAssignment($user, $assignedCareWorker),
            'care_plans' => $carePlans,
            'assessments' => $assessments,
            'behaviour' => $behaviour,
            'calendar' => $user->canDo('calendar.viewAny')
                || $user->canDo('calendar.view')
                || $user->canDo('calendar.create')
                || $user->canDo('calendar.manage'),
            'shifts' => $user->canDo('shifts.viewAny')
                || ($assignedCareWorker && $user->canDo('shifts.viewAssigned')),
            'medical' => $user->can('viewMedications', $client),
            'health' => $user->canDo('clinical.observations.viewAny')
                || ($assignedCareWorker && (
                    $user->canDo('clinical.observations.view')
                    || $user->canDo('clinical.observations.viewAssigned')
                )),
            'finance' => $user->canDo('client_funds.manage'),
            'consents' => $user->canDo('consents.viewAny'),
            'risks' => $risks,
            'incidents' => $user->canDo('incidents.viewAny')
                || ($assignedCareWorker && $user->canDo('incidents.viewAssigned')),
            'first_aid' => $user->canDo('hazards.view'),
            // A dedicated client-document read capability does not yet exist.
            // Restrict the staff profile payload to client editors until the
            // document domain receives an explicit read policy.
            'documents' => $documents,
            'portal_access' => $portalAccess,
            'audit' => $user->canDo('audit.viewAny'),
            'privacy' => $user->canDo('privacy.viewRequests'),
            'respite' => $user->canDo('respite.viewAny'),
            'onboarding' => app(ClientOnboardingAccess::class)
                ->forClient($user, $client)['view'],
            'daily_living' => $dailyLiving,
            'meals' => $dailyLiving || $user->can('manageMeals', $client),
            'agreements' => $user->canDo('service_agreements.viewAny'),
            'family_notes' => $familyNotes,
            'photos' => $dailyLiving,
            'personal_assets' => $personalAssets,
            'tracking' => $user->canDo('assets.telemetry.view')
                && (
                    $user->canDo('fleet.viewAny')
                    || $user->canDo('assets.viewAny')
                    || ($assignedCareWorker && $user->canDo('assets.viewAssigned'))
                ),
            'transport' => $user->canDo('fleet.viewAny')
                || $user->canDo('assets.viewAny')
                || ($assignedCareWorker && $user->canDo('assets.viewAssigned')),
            'actions_reviews' => $notes
                || $documents
                || $risks
                || $carePlans
                || $assessments
                || $consents
                || $familyNotes,
            'first_aid_manage' => $user->canDo('hazards.manage'),
        ];
    }

    private function canViewTimelineForAssignment(User $user, bool $assignedCareWorker): bool
    {
        return $user->canDo('timeline.viewAny')
            || ($assignedCareWorker && (
                $user->canDo('timeline.create')
                || $user->canDo('progress_notes.viewAny')
            ));
    }

    private function canViewBehaviourForAssignment(User $user, bool $assignedCareWorker): bool
    {
        return $user->canDo('clinical.behaviour.viewAny')
            || ($assignedCareWorker && (
                $user->canDo('clinical.events.viewAssigned')
                || $user->canDo('clinical.events.view')
            ));
    }

    private function isAssignedCareWorker(User $user, Client $client): bool
    {
        $assigned = $client->relationLoaded('supportWorkers')
            ? $client->supportWorkers->contains('id', $user->id)
            : $client->supportWorkers()->whereKey($user->id)->exists();

        return $assigned
            && $this->workerEligibility->isEligible($client, $user);
    }

    /** @param array<int, string> $bypassPermissions */
    private function canAccessClientSite(
        User $user,
        Client $client,
        array $bypassPermissions,
    ): bool {
        $siteId = is_numeric($client->site_id) && (int) $client->site_id > 0
            ? (int) $client->site_id
            : null;

        return $siteId !== null
            && in_array(
                $siteId,
                $this->siteAccess->accessibleSiteIds($user, $bypassPermissions),
                true,
            );
    }
}
