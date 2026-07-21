<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItTransitionInput;
use App\Models\Asset;
use App\Models\ItService;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ItApiWorkItemService
{
    private const RESPONSE_RELATIONS = [
        'site:id,name,is_active,archived,archived_at',
        'service:id,name',
        'asset:id,name,asset_tag,site_id,home_site_id,client_id',
        'queue:id,name',
        'team:id,name',
        'owner:id,name',
        'assignee:id,name',
    ];

    public function __construct(
        private readonly ItTicketRoutingService $routingService,
        private readonly ItWorkTransitionService $transitionService,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /**
     * Resolve the live execution account and refresh the request identity.
     *
     * Credentials never outlive a revoked/expired identity, an unapproved
     * execution account, or the account's IT management authority.
     */
    public function executionAccount(ItServiceIdentity $identity): ?User
    {
        if (! $identity->exists || ! is_numeric($identity->getKey())) {
            return null;
        }

        $current = ItServiceIdentity::query()
            ->with('actor')
            ->find($identity->getKey());
        if (! $current || ! $current->isActive()) {
            return null;
        }

        $actor = $current->actor;
        if (! $actor || ! $this->isCurrentExecutionAccount($actor)) {
            return null;
        }

        $identity->setRawAttributes($current->getAttributes(), true);
        $identity->setRelation('actor', $actor);

        return $actor;
    }

    public function isCurrentExecutionAccount(User $actor): bool
    {
        if (! $actor->isApproved() || ! $actor->canDo('it.manage')) {
            return false;
        }

        return HrEmployeeProfile::query()
            ->where('user_id', $actor->getKey())
            ->where('is_active', true)
            ->where(function ($profile): void {
                $profile->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', today());
            })
            ->where(function ($profile): void {
                $profile->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', today());
            })
            ->exists();
    }

    public function canUseAbility(ItServiceIdentity $identity, string $ability): bool
    {
        return $this->executionAccount($identity) !== null
            && $identity->hasAbility($ability);
    }

    /** @return list<int> */
    public function allowedSiteIds(ItServiceIdentity $identity): array
    {
        $actor = $this->executionAccount($identity);
        if (! $actor) {
            return [];
        }

        $identitySiteIds = array_map('intval', $identity->allowed_site_ids ?? []);

        return array_values(array_intersect(
            $identitySiteIds,
            $this->workAccess->approvedSiteIds($actor),
        ));
    }

    public function authorizedTicket(
        ItServiceIdentity $identity,
        int $ticketId,
        string $ability,
        bool $forWork,
        bool $lockForUpdate = false,
    ): ?ItTicket {
        $actor = $this->executionAccount($identity);
        if (! $actor || ! $identity->hasAbility($ability)) {
            return null;
        }

        $query = ItTicket::query()->with(self::RESPONSE_RELATIONS);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $ticket = $query->find($ticketId);

        if (! $ticket || ! $this->identityScopeAllows($identity, $actor, $ticket)) {
            return null;
        }

        $actorAllowed = $forWork
            ? $this->workAccess->canWork($actor, $ticket)
            : $this->workAccess->canView($actor, $ticket);

        return $actorAllowed ? $ticket : null;
    }

    public function canCreateWithScope(ItServiceIdentity $identity, array $data): bool
    {
        $actor = $this->executionAccount($identity);
        if (! $actor
            || ! $identity->hasAbility('work:create')
            || ! $identity->allowsWorkType((string) ($data['work_type'] ?? ''))) {
            return false;
        }

        $siteId = isset($data['site_id']) && is_numeric($data['site_id'])
            ? (int) $data['site_id']
            : null;
        $isOrganisationWide = (bool) ($data['is_organisation_wide'] ?? false);

        if (isset($data['it_service_id'])
            && ! $this->serviceIsActive((int) $data['it_service_id'])) {
            return false;
        }

        if ($isOrganisationWide) {
            return $siteId === null
                && $identity->allowsOrganisationWideWork()
                && $this->workAccess->canAssignScope($actor, null, true)
                && ! isset($data['asset_id']);
        }

        if ($siteId === null
            || ! $identity->allowsSite($siteId)
            || ! $this->workAccess->canAssignScope($actor, $siteId, false)) {
            return false;
        }

        return ! isset($data['asset_id'])
            || $this->assetIsAuthorizedForSite($actor, (int) $data['asset_id'], $siteId);
    }

    /** @param array<string, mixed> $data */
    public function create(ItServiceIdentity $identity, array $data): ItTicket
    {
        return DB::transaction(function () use ($identity, $data): ItTicket {
            $actor = $this->executionAccount($identity);
            if (! $actor || ! $identity->hasAbility('work:create')) {
                throw new AuthorizationException('This service identity cannot create IT work.');
            }
            if (! $this->canCreateWithScope($identity, $data)) {
                $this->throwConcealedTicket();
            }

            $ticket = ItTicket::createWithReference([
                'tenant_id' => $identity->tenant_id,
                'requester_user_id' => $actor->id,
                'requested_for_user_id' => $actor->id,
                'source' => 'system',
                'status' => 'open',
                'workflow_state' => 'submitted',
                'requires_approval' => ItTicket::categoryNeedsApproval((string) $data['category']),
                'impact' => 'individual',
                'urgency' => 'normal',
                'is_organisation_wide' => false,
                ...Arr::only($data, ItServiceIdentity::CREATE_FIELDS),
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();

            ItTicketEvent::record($ticket, 'created', $actor->id, [
                'source' => 'service_api',
                'service_identity_id' => $identity->id,
            ]);
            $ticket = $this->routingService->route($ticket, $actor->id);

            AuditLogger::logOrFail('it.api.work_item.created', $ticket, [
                'organization_id' => $identity->tenant_id,
                'actor_id' => $actor->id,
                'service_identity_id' => $identity->id,
            ]);

            return $ticket->load(self::RESPONSE_RELATIONS);
        });
    }

    public function addPublicComment(ItServiceIdentity $identity, ItTicket $ticket, string $body): ItTicketComment
    {
        return DB::transaction(function () use ($identity, $ticket, $body): ItTicketComment {
            $authorized = $this->authorizedTicket(
                $identity,
                (int) $ticket->getKey(),
                'work:comment',
                true,
                true,
            );
            if (! $authorized) {
                $this->throwConcealedTicket();
            }

            $comment = ItTicketComment::query()->create([
                'tenant_id' => $identity->tenant_id,
                'ticket_id' => $authorized->id,
                'author_user_id' => $identity->actor_user_id,
                'body' => $body,
                'is_internal' => false,
            ]);
            ItTicketEvent::record($authorized, 'api_public_comment', $identity->actor_user_id, [
                'comment_id' => $comment->id,
                'service_identity_id' => $identity->id,
            ]);
            AuditLogger::logOrFail('it.api.comment.created', $authorized, [
                'organization_id' => $identity->tenant_id,
                'actor_id' => $identity->actor_user_id,
                'service_identity_id' => $identity->id,
                'comment_id' => $comment->id,
            ]);

            return $comment;
        });
    }

    public function transition(ItServiceIdentity $identity, ItTicket $ticket, ItTransitionInput $input): ItTicket
    {
        return DB::transaction(function () use ($identity, $ticket, $input): ItTicket {
            $authorized = $this->authorizedTicket(
                $identity,
                (int) $ticket->getKey(),
                'work:transition',
                true,
                true,
            );
            if (! $authorized) {
                $this->throwConcealedTicket();
            }

            $transitioned = $this->transitionService->transition($authorized, $input);
            AuditLogger::logOrFail('it.api.transition.completed', $transitioned, [
                'organization_id' => $identity->tenant_id,
                'actor_id' => $identity->actor_user_id,
                'service_identity_id' => $identity->id,
                'to_workflow_state' => $input->to->value,
            ]);

            return $transitioned->load(self::RESPONSE_RELATIONS);
        });
    }

    public function linkedAssetIsVisible(ItServiceIdentity $identity, ItTicket $ticket, Asset $asset): bool
    {
        $actor = $this->executionAccount($identity);

        return $actor !== null
            && $ticket->site_id !== null
            && $this->identityScopeAllows($identity, $actor, $ticket)
            && $this->assetIsAuthorizedForSite($actor, (int) $asset->getKey(), (int) $ticket->site_id);
    }

    public function linkedServiceIsVisible(
        ItServiceIdentity $identity,
        ItTicket $ticket,
        ItService $service,
    ): bool {
        $actor = $this->executionAccount($identity);

        return $actor !== null
            && (int) $ticket->it_service_id === (int) $service->getKey()
            && $this->identityScopeAllows($identity, $actor, $ticket)
            && $this->serviceIsActive((int) $service->getKey());
    }

    private function identityScopeAllows(
        ItServiceIdentity $identity,
        User $actor,
        ItTicket $ticket,
    ): bool {
        if (! $identity->allowsWorkType((string) $ticket->work_type)) {
            return false;
        }
        if ($ticket->is_sensitive
            && (! $identity->allowsSensitiveWork() || ! $actor->canDo('it.viewSensitive'))) {
            return false;
        }

        if ($ticket->site_id === null) {
            return $ticket->is_organisation_wide
                && $identity->allowsOrganisationWideWork()
                && $this->workAccess->canAssignScope($actor, null, true);
        }

        $siteId = (int) $ticket->site_id;

        return $identity->allowsSite($siteId)
            && $this->workAccess->canAssignScope($actor, $siteId, false);
    }

    private function assetIsAuthorizedForSite(User $actor, int $assetId, int $siteId): bool
    {
        $asset = Asset::query()->find($assetId);
        if (! $asset) {
            return false;
        }

        $authoritativeSiteIds = collect([
            $asset->site_id,
            $asset->home_site_id,
            $asset->client_id ? $asset->client()->value('site_id') : null,
        ])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        return $authoritativeSiteIds->count() === 1
            && $authoritativeSiteIds->first() === $siteId
            && Gate::forUser($actor)->allows('view', $asset);
    }

    private function serviceIsActive(int $serviceId): bool
    {
        return ItService::query()
            ->whereKey($serviceId)
            ->where('is_active', true)
            ->where('status', '!=', 'retired')
            ->exists();
    }

    private function throwConcealedTicket(): never
    {
        throw (new ModelNotFoundException)->setModel(ItTicket::class);
    }
}
