<?php

namespace App\Domain\It\Services;

use App\Models\ItProvisioningRequest;
use App\Models\ItQueue;
use App\Models\ItTicket;
use App\Models\User;
use DomainException;

/** Reuses the service desk's current employment, Site and approved-absence boundary. */
final class ItProvisioningResponsibilityService
{
    public function __construct(private readonly ItTicketRoutingEligibility $eligibility) {}

    public function eligible(?int $id, ?int $siteId, bool $available = false): ?User
    {
        // The routing eligibility contract accepts an unsaved scope record, as
        // it does for queue setup. This does not create a second work ticket.
        return $this->eligibility->agent($id, new ItTicket([
            'site_id' => $siteId, 'is_organisation_wide' => $siteId === null, 'is_sensitive' => false,
        ]), $available);
    }

    public function fallback(?int $siteId): array
    {
        foreach (ItQueue::query()->where('is_active', true)->with('team')->orderBy('id')->get() as $queue) {
            $rules = $queue->filter_rules ?? [];
            $sites = array_map('intval', $rules['site_ids'] ?? []);
            if (! ($rules['is_default'] ?? false) || ! $queue->team?->is_active
                || ($sites !== [] && ! in_array($siteId, $sites, true))) {
                continue;
            }
            $owner = $this->eligible($queue->team->manager_user_id, $siteId);
            $cover = $this->eligible(isset($rules['cover_user_id']) ? (int) $rules['cover_user_id'] : null, $siteId);
            if ($owner && $cover && $owner->id !== $cover->id) {
                return ['owner' => $owner, 'cover' => $cover, 'team_id' => $queue->team_id, 'gap' => null];
            }
        }

        return ['owner' => null, 'cover' => null, 'team_id' => null,
            'gap' => 'Configure an active service desk fallback queue with an accountable IT manager and a distinct eligible cover person for this Site.'];
    }

    public function pair(?int $primary, ?int $cover, ?int $siteId, array $excluded = []): array
    {
        $owner = $this->eligible($primary, $siteId);
        $backup = $this->eligible($cover, $siteId);
        if (! $owner || ! $backup || $owner->id === $backup->id
            || in_array((int) $owner->id, $excluded, true) || in_array((int) $backup->id, $excluded, true)) {
            throw new DomainException('Choose an eligible responsible person and distinct cover with current Site access. The requester and beneficiary cannot approve their own work.');
        }

        return [$owner, $backup];
    }

    public function approver(ItProvisioningRequest $request): ?User
    {
        if (! $request->approval_requested_at || $request->approval_status !== 'pending'
            || ($request->approval_expires_at && $request->approval_expires_at->lessThanOrEqualTo(now()))) {
            return null;
        }
        $siteId = app(ItProvisioningAccessService::class)->siteIdFor($request);
        $excluded = [(int) $request->approval_requested_by, (int) $request->created_by,
            (int) $request->employeeProfile?->user_id];
        foreach ([$request->primary_approver_user_id, $request->cover_approver_user_id] as $id) {
            if ($id && ! in_array((int) $id, $excluded, true) && ($user = $this->eligible((int) $id, $siteId, true))) {
                return $user;
            }
        }

        return null;
    }

    public function worker(ItProvisioningRequest $request): ?User
    {
        $request->loadMissing('workflow');
        $siteId = app(ItProvisioningAccessService::class)->siteIdFor($request);
        foreach ([$request->assigned_to_user_id, $request->workflow?->owner_user_id, $request->workflow?->cover_user_id] as $id) {
            if ($id && ($person = $this->eligible((int) $id, $siteId, true))) {
                return $person;
            }
        }

        return null;
    }
}
