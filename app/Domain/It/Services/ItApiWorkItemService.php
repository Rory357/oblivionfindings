<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItTicketCommentResult;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\Asset;
use App\Models\ItApiRequest;
use App\Models\ItService;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

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
        private readonly ItTicketIntakeService $intake,
        private readonly ItTicketInteractionService $interactions,
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

        $locking = DB::transactionLevel() > 0;
        if ($locking) {
            // Match API publication and credential writers, including direct
            // in-process adapters which do not pass through the middleware.
            User::query()->whereIn('id', array_filter([$identity->actor_user_id, $identity->created_by_user_id]))
                ->orderBy('id')->lockForUpdate()->get();
        }
        $current = ItServiceIdentity::query()
            ->when($locking, fn ($query) => $query->lockForUpdate())
            ->find($identity->getKey());
        if (! $current || ! $current->isActive()
            || ($locking && ((int) $current->actor_user_id !== (int) $identity->actor_user_id
                || (int) $current->created_by_user_id !== (int) $identity->created_by_user_id))) {
            return null;
        }

        $actor = $locking
            ? app(AuthorizationEvidenceLockService::class)->lockForUser((int) $current->actor_user_id, ['*'])
            : User::query()->find($current->actor_user_id);
        if (! $actor || ! $this->isCurrentExecutionAccount($actor, $locking)) {
            return null;
        }

        $identity->setRawAttributes($current->getAttributes(), true);
        $identity->setRelation('actor', $actor);

        return $actor;
    }

    public function isCurrentExecutionAccount(User $actor, bool $lockForUpdate = false): bool
    {
        if (! $actor->isApproved() || ! $actor->canDo('it.manage')) {
            return false;
        }

        if ($lockForUpdate) {
            if (DB::transactionLevel() < 1) {
                throw new \LogicException('Current API employment evidence requires a transaction.');
            }
            $profile = HrEmployeeProfile::withTrashed()->where('user_id', $actor->getKey())
                ->lockForUpdate()->first();

            return $profile !== null && ! $profile->trashed() && $profile->is_active
                && ($profile->start_date === null || $profile->start_date->lte(today()))
                && ($profile->end_date === null || $profile->end_date->gte(today()));
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
            $this->workAccess->approvedSiteIds($actor, DB::transactionLevel() > 0),
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
        if ($lockForUpdate || DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $ticket = $query->find($ticketId);

        if (! $ticket || ! $this->identityScopeAllows($identity, $actor, $ticket)) {
            return null;
        }

        $actorAllowed = $forWork
            ? $this->workAccess->canWork($actor, $ticket, DB::transactionLevel() > 0)
            : $this->workAccess->canView($actor, $ticket, DB::transactionLevel() > 0);

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
                && $this->workAccess->canAssignScope($actor, null, true, DB::transactionLevel() > 0)
                && ! isset($data['asset_id']);
        }

        if ($siteId === null
            || ! $identity->allowsSite($siteId)
            || ! $this->workAccess->canAssignScope($actor, $siteId, false, DB::transactionLevel() > 0)) {
            return false;
        }

        return ! isset($data['asset_id'])
            || $this->assetIsAuthorizedForSite($actor, (int) $data['asset_id'], $siteId);
    }

    /** Lock both records in canonical order before either record's Site evidence. */
    public function authorizedRelatedTickets(ItServiceIdentity $identity, int $sourceId, int $targetId): ?array
    {
        $actor = $this->executionAccount($identity);
        if (! $actor || ! $identity->hasAbility('work:link')) {
            return null;
        }
        $tickets = ItTicket::query()->whereIn('id', [$sourceId, $targetId])->orderBy('id')
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $source = $tickets->get($sourceId);
        $target = $tickets->get($targetId);
        foreach ([$source, $target] as $ticket) {
            if (! $ticket || ! $this->identityScopeAllows($identity, $actor, $ticket)
                || ! $this->workAccess->canWork($actor, $ticket, DB::transactionLevel() > 0)) {
                return null;
            }
        }

        return [$source, $target];
    }

    public function update(ItServiceIdentity $identity, ItTicket $ticket, array $data, ItApiRequest $apiRequest): ItTicket
    {
        return DB::transaction(function () use ($identity, $ticket, $data, $apiRequest): ItTicket {
            $ticket = $this->authorizedTicket($identity, (int) $ticket->id, 'work:update', true, true);
            if (! $ticket) {
                $this->throwConcealedTicket();
            }
            app(ItApiFieldPolicy::class)->validateUpdate($identity, $data);
            $saved = app(ItTicketTriageService::class)->updateCommand($ticket, $identity->actor, [
                ...$data, 'request_uuid' => $this->commandUuid($identity, $apiRequest),
            ], ItTicketCommandChannel::ServiceApi);
            AuditLogger::logOrFail('it.api.work_item.updated', $saved, [
                'actor_id' => $identity->actor_user_id, 'service_identity_id' => $identity->id,
                'api_request_id' => $apiRequest->id, 'source' => 'service_api',
            ]);

            return $saved->load(self::RESPONSE_RELATIONS);
        });
    }

    public function changeRelated(ItServiceIdentity $identity, int $sourceId, array $data, ItApiRequest $apiRequest): ItTicket
    {
        return DB::transaction(function () use ($identity, $sourceId, $data, $apiRequest): ItTicket {
            $pair = $this->authorizedRelatedTickets($identity, $sourceId, (int) ($data['target_ticket_id'] ?? 0));
            if (! $pair) {
                $this->throwConcealedTicket();
            }
            [$source, $target] = $pair;
            app(ItTicketLinkService::class)->changeRelated($source, $target, $identity->actor, [
                ...Arr::only($data, ['action', 'relationship', 'source_version', 'target_version']),
                'actor_user_id' => (int) $identity->actor_user_id,
                'request_uuid' => $this->commandUuid($identity, $apiRequest),
            ], ItTicketCommandChannel::ServiceApi);
            AuditLogger::logOrFail('it.api.relationship.changed', $source, [
                'actor_id' => $identity->actor_user_id, 'service_identity_id' => $identity->id,
                'api_request_id' => $apiRequest->id, 'target_ticket_id' => $target->id, 'source' => 'service_api',
            ]);

            return $source->refresh()->load(self::RESPONSE_RELATIONS);
        });
    }

    /** @param array<string, mixed> $data */
    public function create(ItServiceIdentity $identity, array $data, ?ItApiRequest $apiRequest = null): ItTicket
    {
        return DB::transaction(function () use ($identity, $data, $apiRequest): ItTicket {
            $actor = $this->executionAccount($identity);
            if (! $actor || ! $identity->hasAbility('work:create')) {
                throw new AuthorizationException('This service identity cannot create IT work.');
            }
            if (! $this->canCreateWithScope($identity, $data)) {
                $this->throwConcealedTicket();
            }

            $requestUuid = $this->commandUuid($identity, $apiRequest);
            $result = $this->intake->createCommand($actor, [
                ...Arr::only($data, ItServiceIdentity::CREATE_FIELDS),
                'request_uuid' => $requestUuid,
            ], channel: ItTicketCommandChannel::ServiceApi);
            $ticket = $result->ticket;

            AuditLogger::logOrFail('it.api.work_item.created', $ticket, [
                'application_scope' => 'single_installation',
                'actor_id' => $actor->id,
                'service_identity_id' => $identity->id,
                'api_request_id' => $apiRequest?->id,
                'command_request_uuid' => $requestUuid,
            ]);
            $this->scheduleNotifications((int) $ticket->id);

            return $ticket->load(self::RESPONSE_RELATIONS);
        });
    }

    public function addPublicComment(ItServiceIdentity $identity, ItTicket $ticket, string $body, ?ItApiRequest $apiRequest = null): ItTicketComment
    {
        return DB::transaction(function () use ($identity, $ticket, $body, $apiRequest): ItTicketComment {
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

            $requestUuid = $this->commandUuid($identity, $apiRequest);
            $result = $this->interactions->addCommentCommand($authorized, $identity->actor, [
                'request_uuid' => $requestUuid,
                'actor_user_id' => (int) $identity->actor_user_id,
                'expected_version' => (int) $authorized->lock_version,
                'body' => $body,
                'is_internal' => false,
            ], channel: ItTicketCommandChannel::ServiceApi);
            if (! $result instanceof ItTicketCommentResult) {
                throw new \LogicException('An API comment command cannot use a cancelled browser identity.');
            }
            $comment = $result->comment;
            ItTicketEvent::record($authorized, 'api_public_comment', $identity->actor_user_id, [
                'comment_id' => $comment->id,
                'service_identity_id' => $identity->id,
                'command_request_uuid' => $requestUuid,
            ]);
            AuditLogger::logOrFail('it.api.comment.created', $authorized, [
                'application_scope' => 'single_installation',
                'actor_id' => $identity->actor_user_id,
                'service_identity_id' => $identity->id,
                'comment_id' => $comment->id,
                'api_request_id' => $apiRequest?->id,
                'command_request_uuid' => $requestUuid,
            ]);
            $this->scheduleNotifications((int) $authorized->id);

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

            // Service identity provenance is server-owned, including for
            // in-process adapters. Caller labels cannot borrow a requester or
            // legacy transition's special authorization/validation rules.
            $input = new ItTransitionInput(
                actor: $identity->actor,
                to: $input->to,
                reason: $input->reason,
                waitingParty: $input->waitingParty,
                nextAction: $input->nextAction,
                resolutionCode: $input->resolutionCode,
                resolutionSummary: $input->resolutionSummary,
                source: 'service_api',
                expectedVersion: $input->expectedVersion,
                resolutionVerification: $input->resolutionVerification,
                channel: ItTicketCommandChannel::ServiceApi,
            );
            $transitioned = $this->transitionService->transition($authorized, $input);
            AuditLogger::logOrFail('it.api.transition.completed', $transitioned, [
                'application_scope' => 'single_installation',
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
                && $this->workAccess->canAssignScope($actor, null, true, DB::transactionLevel() > 0);
        }

        $siteId = (int) $ticket->site_id;

        return $identity->allowsSite($siteId)
            && $this->workAccess->canAssignScope($actor, $siteId, false, DB::transactionLevel() > 0);
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

    /** Bind trusted adapter identity to the existing canonical command ledger. */
    private function commandUuid(ItServiceIdentity $identity, ?ItApiRequest $apiRequest): string
    {
        if ($apiRequest === null) {
            // Existing in-process adapters have no HTTP replay key.
            return (string) Str::uuid();
        }
        if (! $apiRequest->exists || (int) $apiRequest->service_identity_id !== (int) $identity->id) {
            throw new \LogicException('The API request does not belong to this service identity.');
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'oblivion:it:service-api:'.$identity->id.':'.$apiRequest->id)->toString();
    }

    private function scheduleNotifications(int $ticketId): void
    {
        DB::afterCommit(static function () use ($ticketId): void {
            try {
                DispatchItTicketNotifications::dispatchAfterResponse($ticketId);
            } catch (Throwable) {
                // The canonical durable outbox remains eligible for its
                // existing scheduled drain if response dispatch is unavailable.
                Log::warning('IT API notification dispatch deferred.', ['ticket_id' => $ticketId]);
            }
        });
    }
}
